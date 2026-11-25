<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Inbox,
    LoaderCircle,
    Search,
    X,
} from '@lucide/vue'
import Skeleton from './Skeleton.vue'
import ComboBox from './ComboBox.vue'
import { pathOf, recall, remember, takeReturn } from '../lib/listMemory'

/**
 * The list-screen table for every module.
 *
 * Searching, sorting and paging all happen on the server. The component only
 * sends the query string and shows what comes back, so a table of ten rows
 * and a table of ten thousand rows cost the browser the same.
 *
 * Pair it with App\Support\TableQuery on the controller side.
 */
const props = defineProps({
    /** A Laravel paginator, serialised. */
    data: { type: Object, required: true },

    /**
     * Column definitions:
     * { key, label, sortable, align, width, class, hideOnMobile }
     */
    columns: { type: Array, required: true },

    /** The state from TableQuery::state(). */
    filters: { type: Object, default: () => ({}) },

    /** Where to send the search, sort and page requests. */
    url: { type: String, required: true },

    /** Prop names to reload, so the rest of the page is not re-sent. */
    only: { type: Array, default: () => [] },

    /**
     * Filters this screen adds of its own, for example a date range. They
     * ride along on every search, sort and page change, and a change here
     * reloads the table by itself.
     */
    extraQuery: { type: Object, default: () => ({}) },

    rowKey: { type: String, default: 'id' },
    searchPlaceholder: { type: String, default: 'Search…' },
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyText: { type: String, default: 'Try a different search, or add the first record.' },
    /** Hide the search box for a short, fixed list. */
    searchable: { type: Boolean, default: true },
})

const perPageOptions = [10, 25, 50, 100]

/** The same list, shaped the way a ComboBox wants it. */
const perPageRows = perPageOptions.map((n) => ({ value: n, label: String(n) }))

const search = ref(props.filters.search ?? '')
const loading = ref(false)

let debounceId = null

/** Send the current state to the server. */
function visit(overrides = {}, { replace = true } = {}) {
    const query = {
        ...props.extraQuery,
        search: search.value || undefined,
        sort: props.filters.sort || undefined,
        direction: props.filters.direction || undefined,
        per_page: props.filters.per_page || undefined,
        page: undefined,
        ...overrides,
    }

    // Drop empty values so the address bar stays short.
    Object.keys(query).forEach((key) => {
        if (query[key] === undefined || query[key] === null || query[key] === '') {
            delete query[key]
        }
    })

    router.get(props.url, query, {
        preserveState: true,
        preserveScroll: true,
        replace,
        only: props.only.length ? props.only : undefined,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    })
}

watch(search, (value, previous) => {
    if (value === previous) return

    clearTimeout(debounceId)
    debounceId = setTimeout(() => visit({ search: value || undefined }), 350)
})

onBeforeUnmount(() => clearTimeout(debounceId))

// Keep the box in step when the server sends a different search back, for
// example after the browser's back button.
watch(
    () => props.filters.search,
    (value) => {
        if ((value ?? '') !== search.value) search.value = value ?? ''
    }
)

// A filter the page owns changed, so fetch the first page again.
watch(
    () => props.extraQuery,
    () => visit(),
    { deep: true }
)

/* ------------------------------------------------ Where you were (24 Nov) */

/**
 * The list writes down the address it is showing, how far down the page is
 * scrolled and the row last opened (see `lib/listMemory.js`). A "Back to …"
 * link or a breadcrumb then brings back the same sort, filters, search and
 * page, scrolls back down and marks that row. Only while this table is the
 * list on the page — a table inside another screen keeps nothing.
 */
const ownPath = pathOf(props.url)

const onOwnPage = () => typeof window !== 'undefined' && window.location.pathname === ownPath

/** The row last opened from this list, marked so the eye finds it again. */
const lastOpened = ref(null)

function rememberAddress() {
    if (onOwnPage()) remember(ownPath, { url: window.location.pathname + window.location.search })
}

let scrollFrame = null

function rememberScroll() {
    if (scrollFrame) return

    scrollFrame = requestAnimationFrame(() => {
        scrollFrame = null

        if (onOwnPage()) remember(ownPath, { scroll: Math.round(window.scrollY) })
    })
}

/** A row was opened, edited or acted on: remember it and mark it. */
function touchRow(row) {
    lastOpened.value = row[props.rowKey]

    if (onOwnPage()) remember(ownPath, { row: row[props.rowKey] })
}

let stopListening = null

onMounted(() => {
    rememberAddress()
    stopListening = router.on('navigate', rememberAddress)
    window.addEventListener('scroll', rememberScroll, { passive: true })

    if (!takeReturn(ownPath)) return

    const saved = recall(ownPath)

    lastOpened.value = saved?.row ?? null

    if (saved?.scroll) {
        // After the rows are drawn, or there is nothing to scroll to yet.
        nextTick(() => requestAnimationFrame(() => window.scrollTo({ top: saved.scroll })))
    }
})

onBeforeUnmount(() => {
    stopListening?.()
    window.removeEventListener('scroll', rememberScroll)

    if (scrollFrame) cancelAnimationFrame(scrollFrame)
})

const isLastOpened = (row) => lastOpened.value !== null && String(row[props.rowKey]) === String(lastOpened.value)

/** Let the page reload the table by hand, for example from a Reset button. */
defineExpose({ reload: () => visit() })

function sortBy(column) {
    if (!column.sortable) return

    const isCurrent = props.filters.sort === column.key
    const direction = isCurrent && props.filters.direction === 'asc' ? 'desc' : 'asc'

    visit({ sort: column.key, direction })
}

function changePerPage(event) {
    visit({ per_page: Number(event.target.value) })
}

function clearSearch() {
    search.value = ''
}

const rows = computed(() => props.data?.data ?? [])
const links = computed(() => props.data?.links ?? [])

/** The numbered page buttons, without Laravel's prev and next entries. */
const pageLinks = computed(() => links.value.slice(1, -1))
const previous = computed(() => links.value[0] ?? null)
const next = computed(() => links.value[links.value.length - 1] ?? null)

const showingFrom = computed(() => props.data?.from ?? 0)
const showingTo = computed(() => props.data?.to ?? 0)
const total = computed(() => props.data?.total ?? 0)

const hasSearch = computed(() => (search.value ?? '') !== '')
const isEmpty = computed(() => rows.value.length === 0)

/** Columns shown on the stacked card view. */
const mobileColumns = computed(() => props.columns.filter((column) => !column.hideOnMobile))

const alignClass = (column) =>
    ({
        right: 'text-right',
        center: 'text-center',
    })[column.align] ?? 'text-left'

const sortIcon = (column) => {
    if (props.filters.sort !== column.key) return ArrowUpDown

    return props.filters.direction === 'asc' ? ArrowUp : ArrowDown
}
</script>

<template>
    <div class="card overflow-hidden">
        <!-- Toolbar -->
        <div
            class="flex flex-col gap-3 border-b border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex flex-1 items-center gap-2">
                <label v-if="searchable" class="relative w-full sm:max-w-xs">
                    <span class="sr-only">{{ searchPlaceholder }}</span>
                    <Search
                        :size="16"
                        class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-subtle"
                        aria-hidden="true"
                    />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="searchPlaceholder"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted pr-9 pl-9 text-sm text-ink placeholder:text-ink-subtle focus:border-brand-300 focus:bg-surface"
                    />
                    <button
                        v-if="hasSearch"
                        type="button"
                        class="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-md text-ink-subtle hover:bg-surface-sunken hover:text-ink"
                        aria-label="Clear search"
                        @click="clearSearch"
                    >
                        <X :size="14" />
                    </button>
                </label>

                <LoaderCircle
                    v-if="loading"
                    :size="16"
                    class="shrink-0 animate-spin text-brand-500"
                    aria-label="Loading"
                />

                <slot name="toolbar" />
            </div>

            <div class="flex items-center gap-3">
                <slot name="toolbar-end" />

                <div class="flex items-center gap-2 text-xs text-ink-muted">
                    <span class="hidden sm:inline">Rows</span>
                    <ComboBox
                        :model-value="filters.per_page ?? 25"
                        :options="perPageRows"
                        size="sm"
                        class="w-20"
                        aria-label="Rows per page"
                        @update:model-value="changePerPage({ target: { value: $event } })"
                    />
                </div>
            </div>
        </div>

        <!-- Table: wide screens -->
        <div class="scrollbar-slim hidden overflow-x-auto md:block">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-muted">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            :style="column.width ? { width: column.width } : undefined"
                            class="px-4 py-3 text-2xs font-semibold tracking-wider text-ink-muted uppercase"
                            :class="[alignClass(column), column.class]"
                            :aria-sort="
                                filters.sort === column.key
                                    ? filters.direction === 'asc'
                                      ? 'ascending'
                                      : 'descending'
                                    : undefined
                            "
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="group inline-flex items-center gap-1.5 rounded transition-colors hover:text-ink"
                                :class="filters.sort === column.key ? 'text-brand-700' : ''"
                                @click="sortBy(column)"
                            >
                                {{ column.label }}
                                <component
                                    :is="sortIcon(column)"
                                    :size="13"
                                    class="transition-opacity"
                                    :class="
                                        filters.sort === column.key
                                            ? 'opacity-100'
                                            : 'opacity-40 group-hover:opacity-80'
                                    "
                                    aria-hidden="true"
                                />
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <!-- Skeleton rows on the very first load. -->
                    <template v-if="loading && isEmpty">
                        <tr
                            v-for="n in 5"
                            :key="`skeleton-${n}`"
                            class="border-b border-line last:border-0"
                        >
                            <td v-for="column in columns" :key="column.key" class="px-4 py-3.5">
                                <Skeleton :width="n % 2 ? 'w-3/4' : 'w-1/2'" height="h-4" />
                            </td>
                        </tr>
                    </template>

                    <tr v-else-if="isEmpty">
                        <td :colspan="columns.length" class="px-4 py-14">
                            <slot name="empty">
                                <div class="flex flex-col items-center text-center">
                                    <span
                                        class="grid size-12 place-items-center rounded-full bg-surface-sunken text-ink-subtle"
                                    >
                                        <Inbox :size="22" />
                                    </span>
                                    <p class="mt-3 text-sm font-medium text-ink">
                                        {{ hasSearch ? 'No match found' : emptyTitle }}
                                    </p>
                                    <p class="mt-1 max-w-sm text-xs text-ink-subtle">
                                        {{
                                            hasSearch
                                                ? `Nothing matches “${search}”.`
                                                : emptyText
                                        }}
                                    </p>
                                    <button
                                        v-if="hasSearch"
                                        type="button"
                                        class="mt-4 text-xs font-semibold text-brand-700 hover:underline"
                                        @click="clearSearch"
                                    >
                                        Clear the search
                                    </button>
                                </div>
                            </slot>
                        </td>
                    </tr>

                    <template v-else>
                        <tr
                            v-for="row in rows"
                            :key="row[rowKey]"
                            class="border-b border-line transition-colors duration-100 last:border-0 hover:bg-surface-muted"
                            :class="isLastOpened(row) ? 'bg-brand-50/70 shadow-[inset_3px_0_0_var(--color-brand-500)]' : ''"
                            :data-last-opened="isLastOpened(row) ? 'true' : undefined"
                            @click.capture="touchRow(row)"
                        >
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-4 py-3.5 align-middle"
                                :class="[alignClass(column), column.cellClass]"
                            >
                                <slot
                                    :name="`cell-${column.key}`"
                                    :row="row"
                                    :value="row[column.key]"
                                >
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Cards: small screens -->
        <div class="divide-y divide-line md:hidden">
            <div v-if="loading && isEmpty" class="space-y-3 p-4">
                <Skeleton v-for="n in 4" :key="n" width="w-full" height="h-16" shape="block" />
            </div>

            <div v-else-if="isEmpty" class="px-4 py-12">
                <slot name="empty">
                    <div class="flex flex-col items-center text-center">
                        <span
                            class="grid size-12 place-items-center rounded-full bg-surface-sunken text-ink-subtle"
                        >
                            <Inbox :size="22" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink">
                            {{ hasSearch ? 'No match found' : emptyTitle }}
                        </p>
                        <p class="mt-1 text-xs text-ink-subtle">
                            {{ hasSearch ? `Nothing matches “${search}”.` : emptyText }}
                        </p>
                    </div>
                </slot>
            </div>

            <template v-else>
                <div
                    v-for="row in rows"
                    :key="row[rowKey]"
                    class="space-y-2 p-4 transition-colors hover:bg-surface-muted"
                    :class="isLastOpened(row) ? 'bg-brand-50/70 shadow-[inset_3px_0_0_var(--color-brand-500)]' : ''"
                    @click.capture="touchRow(row)"
                >
                    <div
                        v-for="column in mobileColumns"
                        :key="column.key"
                        class="flex items-start justify-between gap-3"
                    >
                        <span
                            class="text-2xs font-semibold tracking-wider text-ink-subtle uppercase"
                        >
                            {{ column.label }}
                        </span>
                        <span class="min-w-0 text-right text-sm text-ink">
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="row[column.key]"
                            >
                                {{ row[column.key] ?? '—' }}
                            </slot>
                        </span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Footer -->
        <div
            v-if="!isEmpty"
            class="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-ink-muted">
                Showing <span class="font-semibold text-ink">{{ showingFrom }}</span>
                to <span class="font-semibold text-ink">{{ showingTo }}</span>
                of <span class="font-semibold text-ink">{{ total }}</span>
                {{ total === 1 ? 'record' : 'records' }}
            </p>

            <nav v-if="links.length > 3" class="flex items-center gap-1" aria-label="Pages">
                <component
                    :is="previous?.url ? Link : 'span'"
                    :href="previous?.url ?? undefined"
                    preserve-scroll
                    preserve-state
                    class="grid size-8 place-items-center rounded-field border border-line transition-colors"
                    :class="
                        previous?.url
                            ? 'text-ink-muted hover:bg-surface-sunken hover:text-ink'
                            : 'cursor-not-allowed text-ink-subtle opacity-50'
                    "
                    aria-label="Previous page"
                >
                    <ChevronLeft :size="15" />
                </component>

                <template v-for="(link, index) in pageLinks" :key="index">
                    <span
                        v-if="!link.url"
                        class="grid size-8 place-items-center text-xs text-ink-subtle"
                    >
                        …
                    </span>
                    <Link
                        v-else
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        class="grid size-8 place-items-center rounded-field border text-xs font-medium transition-colors"
                        :class="
                            link.active
                                ? 'border-brand-600 bg-brand-600 text-ink-inverse shadow-brand'
                                : 'border-line text-ink-muted hover:bg-surface-sunken hover:text-ink'
                        "
                        :aria-current="link.active ? 'page' : undefined"
                    >
                        {{ link.label }}
                    </Link>
                </template>

                <component
                    :is="next?.url ? Link : 'span'"
                    :href="next?.url ?? undefined"
                    preserve-scroll
                    preserve-state
                    class="grid size-8 place-items-center rounded-field border border-line transition-colors"
                    :class="
                        next?.url
                            ? 'text-ink-muted hover:bg-surface-sunken hover:text-ink'
                            : 'cursor-not-allowed text-ink-subtle opacity-50'
                    "
                    aria-label="Next page"
                >
                    <ChevronRight :size="15" />
                </component>
            </nav>
        </div>
    </div>
</template>
