<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ExternalLink, Package } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'

/**
 * One product, read without leaving the Stock screen.
 *
 * Both ways into it from Stock land here: the product's name and the view
 * button beside it. They used to go to two different places — the product
 * page and the movement list filtered to it — which is two screens for one
 * question. The batches and the stock card are both in here, and "See all"
 * still opens the full movement list for anyone who needs to filter it.
 *
 * The payload is the controller's own `detail()`, the same one the product
 * page renders, so the dialog cannot show a different figure.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    id: { type: [Number, String], default: null },
})

defineEmits(['close'])

const { money } = useMoney()

const url = computed(() => (props.id ? `/products/${props.id}/preview` : null))
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            v-if="data.product.photo_url"
                            class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-field border border-line bg-surface-sunken"
                        >
                            <img
                                :src="data.product.photo_url"
                                :alt="data.product.name"
                                class="size-full object-cover"
                            />
                        </span>
                        <div class="min-w-0">
                        <div class="flex items-center gap-2.5">
                            <h3 class="truncate text-base font-bold text-ink">{{ data.product.name }}</h3>
                            <ActionBadge
                                :label="data.product.is_active ? 'Active' : 'Inactive'"
                                :tone="data.product.is_active ? 'success' : 'neutral'"
                            />
                        </div>
                        <p class="font-mono text-xs text-ink-subtle">
                            {{ data.product.sku }}<template v-if="data.product.barcode"> · {{ data.product.barcode }}</template>
                        </p>
                        </div>
                    </div>

                    <Link
                        :href="`/products/${data.product.id}`"
                        class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                    >
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <!-- The figures, as the Stock screen states them -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-field bg-surface-sunken p-3">
                        <p class="text-xs text-ink-muted">On hand</p>
                        <p class="mt-0.5 tabular-nums text-xl font-bold text-ink">
                            {{ count(data.product.on_hand) }}
                            <span class="text-xs font-medium text-ink-muted">{{ data.product.unit }}</span>
                        </p>
                    </div>
                    <div class="rounded-field bg-surface-sunken p-3">
                        <p class="text-xs text-ink-muted">Selling price</p>
                        <p class="mt-0.5 tabular-nums text-xl font-bold text-ink">
                            {{ money(data.product.selling_price) }}
                        </p>
                    </div>
                    <div v-if="data.stockValue !== null" class="rounded-field bg-surface-sunken p-3">
                        <p class="text-xs text-ink-muted">Stock value</p>
                        <p class="mt-0.5 tabular-nums text-xl font-bold text-ink">
                            {{ money(data.stockValue) }}
                        </p>
                    </div>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Brand', value: data.product.brand ?? '—' },
                            { label: 'Model or size', value: data.product.model ?? '—' },
                            { label: 'Category', value: data.product.category ?? '—' },
                            { label: 'Unit', value: data.product.unit_name ?? '—' },
                            { label: 'Preferred supplier', value: data.product.preferred_supplier ?? '—' },
                            { label: 'Reorder level', value: count(data.product.reorder_level) },
                            { label: 'Last changed', value: data.product.updated_at ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>

                <!-- Where the stock sits (phase 31.2). -->
                <section v-if="data.locations?.length" class="overflow-hidden rounded-field border border-line">
                    <p class="border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        By location
                    </p>
                    <div class="divide-y divide-line">
                        <div
                            v-for="place in data.locations"
                            :key="place.location"
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span class="min-w-0 text-ink">
                                {{ place.location }}
                                <span class="text-xs text-ink-subtle">· {{ place.type }}<template v-if="place.is_default"> · default</template></span>
                            </span>
                            <span class="tabular-nums font-semibold text-ink">
                                {{ count(place.on_hand) }}
                                <span class="text-2xs font-normal text-ink-subtle">{{ data.product.unit }}</span>
                            </span>
                        </div>
                    </div>
                </section>

                <!-- The batches, newest first, with the one the next sale eats -->
                <section v-if="data.batches.length" class="overflow-hidden rounded-field border border-line">
                    <p class="border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        Batches on the shelf
                    </p>
                    <div class="divide-y divide-line">
                        <div
                            v-for="batch in data.batches"
                            :key="batch.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_5rem_6rem_6rem] sm:items-center sm:gap-3"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="truncate font-mono text-xs font-semibold text-ink">
                                    {{ batch.reference }}
                                </span>
                                <span
                                    v-if="batch.is_next"
                                    class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-2xs font-semibold tracking-wide text-brand-700 uppercase"
                                >
                                    Next out
                                </span>
                            </div>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">
                                {{ count(batch.quantity_remaining) }}
                                <span class="text-2xs">of {{ count(batch.quantity_received) }}</span>
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">
                                {{ money(batch.unit_cost) }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">
                                {{ money(batch.value) }}
                            </span>
                        </div>
                    </div>
                </section>

                <!-- The stock card. Filtering it is a job for the full screen. -->
                <section v-if="data.movements.length" class="overflow-hidden rounded-field border border-line">
                    <div class="flex items-center justify-between border-b border-line bg-surface-muted px-4 py-2">
                        <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                            Stock card
                        </p>
                        <Link
                            :href="`/movements?product_id=${data.product.id}`"
                            class="text-xs font-medium text-brand-700 hover:underline"
                        >
                            See all
                        </Link>
                    </div>
                    <div class="divide-y divide-line">
                        <div
                            v-for="movement in data.movements"
                            :key="movement.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_7rem_4rem_4rem_5rem] sm:items-center sm:gap-3"
                        >
                            <div class="min-w-0">
                                <Link
                                    v-if="movement.document_url"
                                    :href="movement.document_url"
                                    class="truncate font-mono text-xs font-semibold text-brand-700 hover:underline"
                                >
                                    {{ movement.reference }}
                                </Link>
                                <span v-else class="truncate font-mono text-xs text-ink-muted">
                                    {{ movement.reference }}
                                </span>
                                <p class="text-2xs text-ink-subtle">{{ movement.movement_date }}</p>
                            </div>
                            <ActionBadge :label="movement.type_label" :tone="movement.type_tone" />
                            <span class="tabular-nums text-sm font-medium text-success-700 sm:text-right">
                                {{ movement.quantity_in ? `+${count(movement.quantity_in)}` : '—' }}
                            </span>
                            <span class="tabular-nums text-sm font-medium text-danger-700 sm:text-right">
                                {{ movement.quantity_out ? `−${count(movement.quantity_out)}` : '—' }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">
                                {{ count(movement.balance_after) }}
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </template>
    </RecordModal>
</template>
