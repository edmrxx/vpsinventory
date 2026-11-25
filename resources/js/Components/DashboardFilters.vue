<script setup>
import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX } from '@lucide/vue'
import FilterSelect from './FilterSelect.vue'
import TextField from './TextField.vue'

/**
 * The period and narrowing above the dashboard.
 *
 * Every figure on the screen answers to this bar, so it drives one visit
 * and lets the deferred blocks arrive again on their own. The dates are
 * only shown for a custom range: a date box beside "This month" invites
 * somebody to change it and wonder why nothing happens.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    // True for somebody who may only see their own documents. The server
    // holds them to it whatever is sent; the box only says so.
    staffLocked: { type: Boolean, default: false },
})

const form = reactive({
    period: props.filters.period,
    from: props.filters.from,
    to: props.filters.to,
    category_id: props.filters.category_id,
    product_id: props.filters.product_id,
    staff_id: props.filters.staff_id,
})

watch(
    () => props.filters,
    (incoming) => Object.assign(form, incoming)
)

const custom = computed(() => form.period === 'custom')

const narrowed = computed(
    () =>
        form.period !== 'today' ||
        form.category_id !== null ||
        form.product_id !== null ||
        (!props.staffLocked && form.staff_id !== null)
)

function apply() {
    router.get(
        '/dashboard',
        {
            period: form.period,
            from: custom.value ? form.from : undefined,
            to: custom.value ? form.to : undefined,
            category_id: form.category_id || undefined,
            product_id: form.product_id || undefined,
            staff_id: props.staffLocked ? undefined : form.staff_id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    )
}

function clear() {
    form.period = 'today'
    form.category_id = null
    form.product_id = null
    if (!props.staffLocked) form.staff_id = null
    apply()
}

// A pick applies at once. A custom range waits for both dates, or the
// screen would reload on the way to typing the second one.
watch(() => [form.period, form.category_id, form.product_id, form.staff_id], apply)
watch(
    () => [form.from, form.to],
    () => {
        if (custom.value && form.from && form.to) apply()
    }
)
</script>

<template>
    <div class="card mb-4 p-4 sm:p-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-5">
            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    Period
                </span>
                <FilterSelect v-model="form.period" :options="options.periods" />
            </label>

            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    Staff
                </span>
                <FilterSelect
                    v-model="form.staff_id"
                    :options="options.staff ?? []"
                    any-label="All staff"
                    :disabled="staffLocked"
                />
                <span v-if="staffLocked" class="mt-1 block text-2xs text-ink-subtle">
                    You see the documents you posted.
                </span>
            </label>

            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    Category
                </span>
                <FilterSelect
                    v-model="form.category_id"
                    :options="options.categories"
                    placeholder="All categories"
                />
            </label>

            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    Product
                </span>
                <FilterSelect
                    v-model="form.product_id"
                    :options="options.products"
                    placeholder="All products"
                />
            </label>

            <div v-if="custom" class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        From
                    </span>
                    <TextField v-model="form.from" type="date" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        To
                    </span>
                    <TextField v-model="form.to" type="date" />
                </label>
            </div>

            <div v-else class="flex items-end">
                <button
                    v-if="narrowed"
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-xs font-medium text-ink-muted ring-1 ring-line transition hover:bg-surface-sunken"
                    @click="clear"
                >
                    <FilterX :size="14" />
                    Clear
                </button>
            </div>
        </div>

        <p class="mt-3 text-xs text-ink-subtle">
            Showing <span class="font-medium text-ink">{{ filters.label }}</span
            >. Every figure below covers this period.
        </p>
    </div>
</template>
