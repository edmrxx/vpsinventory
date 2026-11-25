<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Package, Pencil, TriangleAlert, Trash2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import Modal from '../../Components/Modal.vue'
import ProductFormFields from './ProductFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { productPickers } from '../../lib/productPickers'
import StatusPill from '../../Components/StatusPill.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { confirmDelete, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    product: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    movements: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    /** What sits at each location (phase 31.2), the default first. */
    locations: { type: Array, default: () => [] },
    stockValue: { type: [String, null], default: null },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const details = computed(() => [
    { label: 'Brand', value: props.product.brand ?? '—' },
    { label: 'Model or size', value: props.product.model ?? '—' },
    { label: 'Barcode', value: props.product.barcode ?? '—', mono: true },
    { label: 'Category', value: props.product.category ?? '—' },
    {
        label: 'Unit',
        value: props.product.unit_name
            ? `${props.product.unit} — ${props.product.unit_name}`
            : (props.product.unit ?? '—'),
    },
    { label: 'Cost', value: money(props.product.cost) },
    { label: 'Selling price', value: money(props.product.selling_price) },
    // A box of 100 at its own price: one line per other unit.
    ...(props.product.other_units ?? []).map((other) => ({
        label: `Per ${other.code}`,
        value: `${money(other.price)} · holds ${count(other.factor)} ${props.product.unit ?? ''}`.trim(),
    })),
    { label: 'Reorder level', value: count(props.product.reorder_level) },
    {
        label: 'Preferred supplier',
        value: props.product.preferred_supplier
            ? `${props.product.preferred_supplier}${props.product.preferred_supplier_active === false ? ' (inactive)' : ''}`
            : '—',
    },
    { label: 'Added', value: props.product.created_at ?? '—' },
    { label: 'Last changed', value: props.product.updated_at ?? '—' },
])

/** The margin between what it costs and what it sells for. */
const margin = computed(() => {
    const cost = Number(props.product.cost ?? 0)
    const price = Number(props.product.selling_price ?? 0)

    if (price <= 0) return null

    return {
        amount: price - cost,
        percent: Math.round(((price - cost) / price) * 100),
    }
})

/* Editing opens the same dialog as the list, over this screen. */
const modal = useFormModal({
    url: '/products',
    noun: 'product',
    label: (row) => row.sku,
    fields: [
        'sku', 'barcode', 'name', 'brand', 'model', 'description', 'category_id',
        'unit_id', 'preferred_supplier_id', 'cost', 'selling_price', 'reorder_level',
        'units', 'units.*',
    ],
    blank: {
        sku: '', barcode: '', name: '', brand: '', model: '', description: '',
        category_id: '', unit_id: '', preferred_supplier_id: '',
        cost: '0.00', selling_price: '0.00', reorder_level: '0', is_active: true,
        units: [],
    },
    fromRow: (row) => ({
        sku: row.sku ?? '',
        barcode: row.barcode ?? '',
        name: row.name ?? '',
        brand: row.brand ?? '',
        model: row.model ?? '',
        description: row.description ?? '',
        category_id: row.category_id ?? '',
        unit_id: row.unit_id ?? '',
        preferred_supplier_id: row.preferred_supplier_id ?? '',
        cost: row.cost ?? '0.00',
        selling_price: row.selling_price ?? '0.00',
        reorder_level: row.reorder_level ?? 0,
        is_active: row.is_active,
        // Sent back whole: a product form that left these out would take
        // every other unit off the product.
        units: (row.other_units ?? []).map((other) => ({
            unit_id: other.unit_id,
            factor: other.factor,
            selling_price: other.selling_price ?? '',
        })),
    }),
})

const pickers = computed(() => productPickers(props.options, modal.editing.value))

async function removeProduct() {
    if (!(await confirmDelete({ name: `${props.product.sku} — ${props.product.name}` }))) return

    router.delete(`/products/${props.product.id}`, {
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The product was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="product.name"
            :subheading="product.sku"
            back-href="/products"
            back-label="Back to products"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.update"
                    variant="secondary"
                    @click="modal.openEdit(product)"
                >
                    <Pencil :size="15" />
                    Edit
                </PrimaryButton>
                <PrimaryButton v-if="can.delete" variant="danger" @click="removeProduct">
                    <Trash2 :size="15" />
                    Delete
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <!-- The photo takes the place of the badge when there
                         is one, so the page does not carry both. -->
                    <span
                        v-if="product.photo_url"
                        class="size-14 shrink-0 overflow-hidden rounded-full border border-line bg-surface-sunken"
                    >
                        <img
                            :src="product.photo_url"
                            :alt="product.name"
                            class="size-full object-cover"
                        />
                    </span>
                    <span
                        v-else
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand"
                    >
                        <Package :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-ink">{{ product.name }}</p>
                        <div class="mt-1.5">
                            <StatusPill :active="product.is_active" />
                        </div>
                    </div>
                </div>

                <dl class="mt-6 space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd
                            class="min-w-0 text-right text-sm break-words text-ink"
                            :class="detail.mono ? 'font-mono text-xs' : ''"
                        >
                            {{ detail.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <!-- Stock -->
                <section class="card p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-ink">On hand</h3>
                            <p class="mt-0.5 text-xs text-ink-subtle">
                                Moved only by a posted document.
                            </p>
                        </div>

                        <p
                            class="text-3xl font-bold tabular-nums"
                            :class="product.is_low_stock ? 'text-danger-700' : 'text-ink'"
                        >
                            {{ count(product.on_hand) }}
                            <span class="text-sm font-medium text-ink-subtle">
                                {{ product.unit }}
                            </span>
                        </p>
                    </div>

                    <p
                        v-if="product.is_low_stock"
                        class="mt-4 flex items-start gap-2 rounded-field bg-danger-50 p-3 text-xs font-medium text-danger-700"
                    >
                        <TriangleAlert :size="15" class="mt-px shrink-0" />
                        At or below the reorder level of {{ count(product.reorder_level) }}.
                        Time to buy more.
                    </p>
                </section>

                <!-- Margin -->
                <section v-if="margin" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Margin</h3>
                    <p class="mt-2 text-sm text-ink-muted">
                        {{ money(margin.amount) }} on every {{ product.unit }},
                        or {{ margin.percent }}% of the selling price.
                    </p>
                    <p class="mt-1 text-xs text-ink-subtle">
                        Worked from the cost on this record. The real margin per sale comes
                        from the FIFO layers the sale consumed.
                    </p>
                </section>

                <!-- Description -->
                <section class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Description</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">
                        {{ product.description ?? 'No description.' }}
                    </p>

                </section>

                <!-- Where the stock sits (phase 31.2). -->
                <section v-if="locations.length" class="card overflow-hidden">
                    <div class="border-b border-line px-5 py-3.5">
                        <h3 class="text-sm font-semibold text-ink">By location</h3>
                    </div>
                    <dl class="divide-y divide-line">
                        <div
                            v-for="place in locations"
                            :key="place.location"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <dt class="min-w-0 text-sm text-ink">
                                {{ place.location }}
                                <span class="text-xs text-ink-subtle">· {{ place.type }}</span>
                                <span
                                    v-if="place.is_default"
                                    class="ml-1.5 rounded-full bg-brand-50 px-2 py-0.5 text-2xs font-semibold text-brand-700"
                                >Default</span>
                            </dt>
                            <dd class="tabular-nums text-sm font-semibold text-ink">
                                {{ count(place.on_hand) }}
                                <span class="text-2xs font-normal text-ink-subtle">{{ product.unit }}</span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- The FIFO queue, newest first so it reads like the stock card
                     below. The batch the next sale eats is therefore the last
                     one, and is marked, because that is the point of the panel. -->
                <section v-if="batches.length" class="card overflow-hidden">
                    <div class="flex items-baseline justify-between gap-3 border-b border-line px-5 py-3.5">
                        <h3 class="text-sm font-semibold text-ink">Batches on the shelf</h3>
                        <span v-if="stockValue !== null" class="text-xs text-ink-muted">
                            Worth <span class="font-semibold text-ink">{{ money(stockValue) }}</span>
                        </span>
                    </div>

                    <p class="px-5 pt-3 text-xs text-ink-subtle">
                        Newest first. The next sale is costed from the oldest batch,
                        marked below.
                    </p>

                    <div class="divide-y divide-line">
                        <div
                            v-for="batch in batches"
                            :key="batch.id"
                            class="grid gap-1.5 px-5 py-3 md:grid-cols-[1fr_6rem_7rem_7rem] md:items-center md:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="flex items-center gap-2">
                                    <span class="truncate font-mono text-xs font-semibold text-ink">
                                        {{ batch.reference }}
                                    </span>
                                    <span
                                        v-if="batch.is_next"
                                        class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-2xs font-semibold tracking-wide text-brand-700 uppercase"
                                        title="The next sale is costed from this batch."
                                    >
                                        Next out
                                    </span>
                                </p>
                                <p class="text-2xs text-ink-subtle">{{ batch.layer_date }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">
                                {{ count(batch.quantity_remaining) }}
                                <span class="text-2xs">of {{ count(batch.quantity_received) }}</span>
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">
                                {{ money(batch.unit_cost) }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">
                                {{ money(batch.value) }}
                            </span>
                        </div>
                    </div>
                </section>

                <!-- The stock card -->
                <section v-if="can.viewMovements" class="card overflow-hidden">
                    <div class="flex items-baseline justify-between gap-3 border-b border-line px-5 py-3.5">
                        <h3 class="text-sm font-semibold text-ink">Stock card</h3>
                        <Link
                            :href="`/movements?product_id=${product.id}`"
                            class="text-xs font-medium text-brand-700 hover:underline"
                        >
                            See all
                        </Link>
                    </div>

                    <p v-if="movements.length === 0" class="px-5 py-4 text-sm text-ink-subtle">
                        This product has never moved.
                    </p>

                    <div v-else class="divide-y divide-line">
                        <div
                            v-for="movement in movements"
                            :key="movement.id"
                            class="grid gap-1.5 px-5 py-3 md:items-center md:gap-3"
                            :class="can.viewCost
                                ? 'md:grid-cols-[1fr_8rem_4rem_4rem_5rem_6rem]'
                                : 'md:grid-cols-[1fr_8rem_4rem_4rem_5rem]'"
                        >
                            <div class="min-w-0">
                                <Link
                                    v-if="movement.document_url"
                                    :href="movement.document_url"
                                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                                >
                                    {{ movement.reference }}
                                </Link>
                                <span v-else class="font-mono text-xs text-ink-muted">
                                    {{ movement.reference }}
                                </span>
                                <p class="text-2xs text-ink-subtle">{{ movement.movement_date }}</p>
                            </div>

                            <ActionBadge :label="movement.type_label" :tone="movement.type_tone" />

                            <span class="tabular-nums text-sm md:text-right">
                                <span v-if="movement.quantity_in > 0" class="font-semibold text-success-700">
                                    +{{ count(movement.quantity_in) }}
                                </span>
                                <span v-else class="text-ink-subtle">—</span>
                            </span>

                            <span class="tabular-nums text-sm md:text-right">
                                <span v-if="movement.quantity_out > 0" class="font-semibold text-danger-700">
                                    −{{ count(movement.quantity_out) }}
                                </span>
                                <span v-else class="text-ink-subtle">—</span>
                            </span>

                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">
                                {{ count(movement.balance_after) }}
                            </span>

                            <span
                                v-if="can.viewCost"
                                class="tabular-nums text-sm text-ink-muted md:text-right"
                            >
                                {{ money(movement.unit_cost) }}
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <ProductFormFields
                :form="modal.form"
                :categories="pickers.categories"
                :units="pickers.units"
                :suppliers="pickers.suppliers"
                :brands="options.brands ?? []"
                mode="edit"
                :on-hand="product.on_hand"
            />

            <template #footer>
                <PrimaryButton
                    variant="secondary"
                    :disabled="modal.form.processing"
                    @click="modal.close()"
                >
                    Cancel
                </PrimaryButton>
                <PrimaryButton :loading="modal.form.processing" @click="modal.submit()">
                    {{ modal.submitLabel.value }}
                </PrimaryButton>
            </template>
        </Modal>
    </div>
</template>
