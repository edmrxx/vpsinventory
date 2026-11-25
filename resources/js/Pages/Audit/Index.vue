<script setup>
import { computed, reactive, watch } from 'vue'
import { Eye, FilterX, Lock } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import PageHeader from '../../Components/PageHeader.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { Swal } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    trails: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'created_at_short', label: 'When', sortable: false, width: '180px' },
    { key: 'username', label: 'User', sortable: true },
    { key: 'module', label: 'Module', sortable: true, width: '140px' },
    { key: 'action', label: 'Action', sortable: true, width: '120px' },
    { key: 'description', label: 'Description' },
    { key: 'ip_address', label: 'From', hideOnMobile: true, width: '160px' },
    { key: 'actions', label: '', align: 'right', width: '60px' },
]

/* The filters this page owns. The DataTable carries them on every change. */
const filterState = reactive({
    user_id: props.filters.user_id ?? '',
    module: props.filters.module ?? '',
    action: props.filters.action ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

// Follow the server, for example after the back button.
watch(
    () => props.filters,
    (incoming) => {
        filterState.user_id = incoming.user_id ?? ''
        filterState.module = incoming.module ?? ''
        filterState.action = incoming.action ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

/** Empty strings are dropped, so the address bar only holds real filters. */
const extraQuery = computed(() => ({
    user_id: filterState.user_id || undefined,
    module: filterState.module || undefined,
    action: filterState.action || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.user_id = ''
    filterState.module = ''
    filterState.action = ''
    filterState.from = ''
    filterState.to = ''
}

/* ----------------------------------------------------------- Row details */

const escape = (value) =>
    String(value ?? '—')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')

function rowDetails(row) {
    const lines = [
        ['When', row.created_at],
        ['User', row.user_name ? `${row.user_name} (@${row.username})` : '—'],
        ['Role at the time', row.role],
        ['Module', row.module],
        ['Action', row.action_label],
        ['IP address', row.ip_address],
        ['Browser', row.browser],
    ]

    let html = `<p class="maf-swal-text" style="margin-bottom:1rem">${escape(row.description)}</p>`

    html += '<dl class="maf-audit-detail">'
    lines.forEach(([label, value]) => {
        html += `<dt>${escape(label)}</dt><dd>${escape(value)}</dd>`
    })
    html += '</dl>'

    const changed = row.properties?.changed
    const created = row.properties?.new

    if (changed) {
        html += '<p class="maf-audit-heading">What changed</p><dl class="maf-audit-detail">'
        Object.entries(changed).forEach(([field, change]) => {
            const from = Array.isArray(change?.from) ? change.from.join(', ') : change?.from
            const to = Array.isArray(change?.to) ? change.to.join(', ') : change?.to

            html += `<dt>${escape(field)}</dt><dd>
                <span class="maf-audit-from">${escape(from === null || from === '' ? 'empty' : from)}</span>
                <span class="maf-audit-arrow">&rarr;</span>
                <span class="maf-audit-to">${escape(to === null || to === '' ? 'empty' : to)}</span>
            </dd>`
        })
        html += '</dl>'
    }

    if (created) {
        html += '<p class="maf-audit-heading">Values at creation</p><dl class="maf-audit-detail">'
        Object.entries(created).forEach(([field, value]) => {
            const shown = Array.isArray(value) ? value.join(', ') : value
            html += `<dt>${escape(field)}</dt><dd>${escape(shown === null || shown === '' ? 'empty' : shown)}</dd>`
        })
        html += '</dl>'
    }

    return html
}

function showDetails(row) {
    Swal.fire({
        title: `${row.module} · ${row.action_label}`,
        html: rowDetails(row),
        width: 560,
        confirmButtonText: 'Close',
        confirmButtonColor: 'oklch(0.55 0.2 286)',
        customClass: {
            popup: 'maf-swal-popup',
            title: 'maf-swal-title',
            actions: 'maf-swal-actions',
            confirmButton: 'maf-swal-confirm',
        },
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Audit Trail"
            subheading="Every recorded action, newest first."
        >
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-3 py-1.5 text-2xs font-semibold tracking-wide text-ink-muted uppercase"
                >
                    <Lock :size="12" />
                    Read only
                </span>
            </template>
        </PageHeader>

        <!-- Filters -->
        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FilterSelect
                    v-model="filterState.user_id"
                    label="User"
                    any-label="Everyone"
                    :options="options.users ?? []"
                />

                <FilterSelect
                    v-model="filterState.module"
                    label="Module"
                    any-label="All modules"
                    :options="options.modules ?? []"
                />

                <FilterSelect
                    v-model="filterState.action"
                    label="Action"
                    any-label="All actions"
                    :options="options.actions ?? []"
                />

                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        From
                    </span>
                    <input
                        v-model="filterState.from"
                        type="date"
                        :max="filterState.to || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        To
                    </span>
                    <input
                        v-model="filterState.to"
                        type="date"
                        :min="filterState.from || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
            </div>

            <div v-if="hasFilters" class="mt-3 flex justify-end">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 transition-colors hover:underline"
                    @click="clearFilters"
                >
                    <FilterX :size="14" />
                    Clear the filters
                </button>
            </div>
        </div>

        <DataTable
            :data="trails"
            :columns="columns"
            :filters="filters"
            :extra-query="extraQuery"
            url="/audit"
            :only="['trails', 'filters', 'options']"
            search-placeholder="Search description, user or IP…"
            empty-title="Nothing recorded yet"
            empty-text="Actions appear here as soon as they happen."
        >
            <template #cell-created_at_short="{ row }">
                <span class="whitespace-nowrap text-xs text-ink-muted">
                    {{ row.created_at_short }}
                </span>
            </template>

            <template #cell-username="{ row }">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">
                        {{ row.user_name ?? 'System' }}
                    </p>
                    <p class="truncate text-2xs text-ink-subtle">
                        <span v-if="row.username">@{{ row.username }}</span>
                        <span v-if="row.role"> · {{ row.role }}</span>
                    </p>
                </div>
            </template>

            <template #cell-module="{ row }">
                <span class="text-sm text-ink-muted">{{ row.module }}</span>
            </template>

            <template #cell-action="{ row }">
                <ActionBadge :label="row.action_label" :tone="row.action_tone" />
            </template>

            <template #cell-description="{ row }">
                <span class="text-sm text-ink">{{ row.description }}</span>
            </template>

            <template #cell-ip_address="{ row }">
                <div class="min-w-0">
                    <p class="truncate font-mono text-2xs text-ink-muted">
                        {{ row.ip_address ?? '—' }}
                    </p>
                    <p class="truncate text-2xs text-ink-subtle">{{ row.browser ?? '—' }}</p>
                </div>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex justify-end">
                    <TableAction
                        variant="view"
                        :icon="Eye"
                        label="See the full record"
                        @click="showDetails(row)"
                    />
                </div>
            </template>
        </DataTable>
    </div>
</template>
