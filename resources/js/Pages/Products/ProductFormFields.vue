<script setup>
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ImageUp, Lock, Plus, TriangleAlert, Trash2 } from '@lucide/vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'
import { count, lineTotal, quantityStep, useMoney } from '../../lib/money'

/**
 * The fields shared by the new-product and edit-product forms.
 *
 * Stock is shown but never edited. It moves only through a posted document,
 * so the edit form prints it as a locked figure.
 */
const props = defineProps({
    form: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    /** Active suppliers, plus the one this product already names. */
    suppliers: { type: Array, default: () => [] },
    /** Brands already typed on other products, offered as suggestions. */
    brands: { type: Array, default: () => [] },
    /** "create" | "edit" */
    mode: { type: String, default: 'create' },
    /** The stock on hand, on the edit form only. 2.5 for a unit sold by measure. */
    onHand: { type: Number, default: null },
    /** The picture already saved against this product, on an edit. */
    photoUrl: { type: String, default: null },
})

const { money, symbol } = useMoney()

/** One id per dialog, so the brand suggestions never cross two forms. */
const brandListId = `product-brands-${Math.random().toString(36).slice(2, 9)}`

/** The unit the stock is counted in, as picked above. */
const ownUnit = computed(() =>
    props.units.find((unit) => String(unit.value) === String(props.form.unit_id)) ?? null
)

/** Just the code, "pcs", for the sentences below. */
const ownCode = computed(() => ownUnit.value?.code ?? 'pieces')

/** The reorder level may be 2.5 only on a unit that allows decimals. */
const reorderStep = computed(() => quantityStep(ownUnit.value?.allows_decimals))

/* ------------------------------------------------------- The other units */

/**
 * A box of 100, a roll of 75 m: other units this product is bought and
 * sold in. The stock stays counted in its own unit.
 */
const otherUnits = computed(() => props.form.units ?? [])

/** Each row may pick any unit except the product's own and the ones other rows took. */
function unitChoices(index) {
    const taken = otherUnits.value
        .filter((_, i) => i !== index)
        .map((row) => String(row.unit_id))

    return props.units.filter(
        (unit) => String(unit.value) !== String(props.form.unit_id) && !taken.includes(String(unit.value))
    )
}

/** What one sells for when its own price is left empty. */
function piecePriceTimes(row) {
    return lineTotal(props.form.selling_price, row.factor)
}

/** What one piece of it comes to, so a box price can be weighed against the piece price. */
function eachPiece(row) {
    const factor = Number(row.factor)

    if (!(factor > 0)) return null

    const price = row.selling_price === '' || row.selling_price === null
        ? piecePriceTimes(row)
        : Number(row.selling_price)

    return price / factor
}

function addOtherUnit() {
    props.form.units = [...otherUnits.value, { unit_id: '', factor: '', selling_price: '' }]
}

function removeOtherUnit(index) {
    props.form.units = otherUnits.value.filter((_, i) => i !== index)
}

function otherUnitError(index, field) {
    return props.form.errors[`units.${index}.${field}`]
}

/* ------------------------------------------------------------- The photo */

const fileInput = ref(null)

/**
 * What the browser made of the file the user just picked.
 *
 * Shown instead of the saved picture, so somebody sees what they chose
 * before they save it rather than after.
 */
const chosen = ref(null)

/* The dialog is reused for the next record, so the preview is dropped
   whenever the form is reset back to a different product. */
watch(
    () => props.form.photo,
    (file) => {
        if (chosen.value) URL.revokeObjectURL(chosen.value)

        chosen.value = file instanceof File ? URL.createObjectURL(file) : null

        // The dialog is reused for the next product, and a file input keeps
        // whatever name it was last given. Without this the box would still
        // read "phone.jpg" over a product that has no photo at all.
        if (! file && fileInput.value) fileInput.value.value = ''
    }
)

const preview = computed(() => {
    if (chosen.value) return chosen.value

    return props.form.remove_photo ? null : props.photoUrl
})

function pickPhoto(event) {
    const file = event.target.files?.[0] ?? null

    props.form.photo = file
    props.form.remove_photo = false
}

/**
 * Take the picture off.
 *
 * On an edit this has to be said out loud: sending no file means "leave it
 * alone", so there would otherwise be no way to go back to no picture.
 */
function clearPhoto() {
    props.form.photo = null
    props.form.remove_photo = true

    if (fileInput.value) fileInput.value.value = ''
}
</script>

<template>
    <div class="space-y-6">
        <!-- A product must name a category and a unit, so say so before the
             person fills the whole form and is refused at the end. -->
        <p
            v-if="categories.length === 0 || units.length === 0"
            class="flex items-start gap-2 rounded-field bg-warning-50 p-3.5 text-xs font-medium text-warning-700"
        >
            <TriangleAlert :size="15" class="mt-px shrink-0" />
            <span>
                <template v-if="categories.length === 0 && units.length === 0">
                    There is no active category and no active unit yet. Add
                    <Link href="/categories" class="underline">a category</Link>
                    and <Link href="/units" class="underline">a unit</Link> first.
                </template>
                <template v-else-if="categories.length === 0">
                    There is no active category yet. Add
                    <Link href="/categories" class="underline">a category</Link> first.
                </template>
                <template v-else>
                    There is no active unit yet. Add
                    <Link href="/units" class="underline">a unit</Link> first.
                </template>
            </span>
        </p>

        <!-- Identity -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Product</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                The SKU names this product on every document, so keep it stable.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="SKU"
                    for="sku"
                    required
                    :error="form.errors.sku"
                    hint="Capitals, numbers, dots, dashes and underscores."
                >
                    <TextField
                        id="sku"
                        v-model="form.sku"
                        :invalid="!!form.errors.sku"
                        placeholder="TSHIRT-BLK"
                    />
                </FormField>

                <FormField label="Product name" for="name" required :error="form.errors.name">
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="T-Shirt (Black)"
                    />
                </FormField>

                <FormField
                    label="Brand"
                    for="brand"
                    :error="form.errors.brand"
                    hint="Optional. Pick one already used to keep the spelling the same."
                >
                    <TextField
                        id="brand"
                        v-model="form.brand"
                        :invalid="!!form.errors.brand"
                        :list="brandListId"
                        autocomplete="off"
                        placeholder="Boysen"
                    />
                    <datalist :id="brandListId">
                        <option v-for="brand in brands" :key="brand.value" :value="brand.value" />
                    </datalist>
                </FormField>

                <FormField
                    label="Model or size"
                    for="model"
                    :error="form.errors.model"
                    hint="Optional."
                >
                    <TextField
                        id="model"
                        v-model="form.model"
                        :invalid="!!form.errors.model"
                        placeholder="4 L, 2.0 mm, B-100"
                    />
                </FormField>

                <FormField
                    label="Barcode"
                    for="barcode"
                    :error="form.errors.barcode"
                    hint="Optional. Click here and scan the label, or type the numbers under the bars."
                >
                    <TextField
                        id="barcode"
                        v-model="form.barcode"
                        :invalid="!!form.errors.barcode"
                        autocomplete="off"
                        placeholder="4800016123456"
                        class="font-mono"
                        @keydown.enter.prevent
                    />
                </FormField>

                <FormField
                    label="Preferred supplier"
                    for="preferred_supplier_id"
                    :error="form.errors.preferred_supplier_id"
                    hint="Optional. Filled in on a new purchase request for this product."
                >
                    <SelectField
                        id="preferred_supplier_id"
                        v-model="form.preferred_supplier_id"
                        :options="suppliers"
                        :invalid="!!form.errors.preferred_supplier_id"
                        placeholder="No preferred supplier"
                    />
                </FormField>

                <FormField
                    label="Category"
                    for="category_id"
                    required
                    :error="form.errors.category_id"
                >
                    <SelectField
                        id="category_id"
                        v-model="form.category_id"
                        :options="categories"
                        :invalid="!!form.errors.category_id"
                        placeholder="Pick a category"
                    />
                </FormField>

                <FormField label="Unit" for="unit_id" required :error="form.errors.unit_id">
                    <SelectField
                        id="unit_id"
                        v-model="form.unit_id"
                        :options="units"
                        :invalid="!!form.errors.unit_id"
                        placeholder="Pick a unit"
                    />
                </FormField>

                <FormField
                    label="Description"
                    for="description"
                    :error="form.errors.description"
                    hint="Optional."
                    class="sm:col-span-2"
                >
                    <TextareaField
                        id="description"
                        v-model="form.description"
                        :rows="2"
                        :invalid="!!form.errors.description"
                    />
                </FormField>
            </div>
        </section>

        <!-- The picture -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Photo</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                Optional. Shown on the lists and on the till, where a picture is quicker to
                find than a name. JPG, PNG or WebP, up to 2&nbsp;MB.
            </p>

            <div class="mt-5 flex flex-wrap items-start gap-5">
                <div
                    class="grid size-28 shrink-0 place-items-center overflow-hidden rounded-field border border-line bg-surface-sunken"
                >
                    <img
                        v-if="preview"
                        :src="preview"
                        alt=""
                        class="size-full object-cover"
                    />
                    <ImageUp v-else :size="24" class="text-ink-subtle" />
                </div>

                <div class="min-w-0 flex-1 space-y-2">
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="block w-full text-xs text-ink-muted file:mr-3 file:cursor-pointer file:rounded-field file:border-0 file:bg-surface-muted file:px-3.5 file:py-2.5 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-sunken"
                        @change="pickPhoto"
                    />

                    <button
                        v-if="preview"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-field px-2 py-1 text-xs font-medium text-danger-700 transition-colors hover:bg-danger-50"
                        @click="clearPhoto"
                    >
                        <Trash2 :size="14" />
                        Remove the photo
                    </button>

                    <p v-if="form.errors.photo" class="text-xs font-medium text-danger-700">
                        {{ form.errors.photo }}
                    </p>
                    <p v-else class="text-xs text-ink-subtle">
                        A large photograph is shrunk when it is saved, so a picture straight
                        off a phone is fine.
                    </p>
                </div>
            </div>
        </section>

        <!-- Money -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Price</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                The cost is what this is usually bought at. The FIFO valuation of the stock
                itself comes from the purchases that brought it in, not from this box.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Cost"
                    for="cost"
                    required
                    :error="form.errors.cost"
                    :hint="`In ${symbol || 'the house currency'}.`"
                >
                    <TextField
                        id="cost"
                        v-model="form.cost"
                        type="number"
                        step="0.01"
                        min="0"
                        :invalid="!!form.errors.cost"
                        placeholder="0.00"
                    />
                </FormField>

                <FormField
                    label="Selling price"
                    for="selling_price"
                    required
                    :error="form.errors.selling_price"
                >
                    <TextField
                        id="selling_price"
                        v-model="form.selling_price"
                        type="number"
                        step="0.01"
                        min="0"
                        :invalid="!!form.errors.selling_price"
                        placeholder="0.00"
                    />
                </FormField>
            </div>
        </section>

        <!-- Other units -->
        <section class="card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Other units</h3>
                    <p class="mt-0.5 text-xs text-ink-subtle">
                        Optional. Buy and sell this by the box, roll or pack as well. The stock
                        stays counted in {{ ownCode }}: selling one box of 100 takes 100
                        {{ ownCode }} off the shelf.
                    </p>
                </div>

                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-brand-700 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!form.unit_id || otherUnits.length >= 10"
                    :title="!form.unit_id ? 'Pick the product\'s own unit first' : ''"
                    @click="addOtherUnit"
                >
                    <Plus :size="16" />
                    Add a unit
                </button>
            </div>

            <p v-if="form.errors.units" class="mt-3 text-xs font-medium text-danger-700">
                {{ form.errors.units }}
            </p>

            <div v-if="otherUnits.length" class="mt-5 space-y-4">
                <div
                    v-for="(row, index) in otherUnits"
                    :key="index"
                    class="grid gap-3 rounded-field border border-line p-3.5 sm:grid-cols-[1fr_1fr_1fr_2.75rem] sm:items-start"
                >
                    <FormField label="Unit" :for="`other_unit_${index}`" required :error="otherUnitError(index, 'unit_id')">
                        <SelectField
                            :id="`other_unit_${index}`"
                            v-model="row.unit_id"
                            :options="unitChoices(index)"
                            :invalid="!!otherUnitError(index, 'unit_id')"
                            placeholder="Pick a unit"
                        />
                    </FormField>

                    <FormField
                        :label="`Holds (${ownCode})`"
                        :for="`other_factor_${index}`"
                        required
                        :error="otherUnitError(index, 'factor')"
                    >
                        <TextField
                            :id="`other_factor_${index}`"
                            v-model="row.factor"
                            type="number"
                            :step="quantityStep(ownUnit?.allows_decimals)"
                            min="0"
                            :invalid="!!otherUnitError(index, 'factor')"
                            placeholder="100"
                        />
                    </FormField>

                    <FormField
                        label="Selling price"
                        :for="`other_price_${index}`"
                        :error="otherUnitError(index, 'selling_price')"
                        :hint="eachPiece(row) !== null ? `${money(eachPiece(row))} per ${ownCode} at this price.` : 'Empty: the piece price times the number it holds.'"
                    >
                        <TextField
                            :id="`other_price_${index}`"
                            v-model="row.selling_price"
                            type="number"
                            step="0.01"
                            min="0"
                            :invalid="!!otherUnitError(index, 'selling_price')"
                            :placeholder="Number(row.factor) > 0 ? piecePriceTimes(row).toFixed(2) : '0.00'"
                        />
                    </FormField>

                    <div class="flex sm:pt-7">
                        <button
                            type="button"
                            class="inline-flex h-11 items-center gap-1.5 rounded-field px-2.5 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50 sm:w-11 sm:justify-center sm:px-0"
                            title="Remove this unit"
                            @click="removeOtherUnit(index)"
                        >
                            <Trash2 :size="16" />
                            <span class="sm:hidden">Remove</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stock -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Stock</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                Stock is never typed in. It moves only through a posted purchase, sale,
                return or adjustment.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Reorder level"
                    for="reorder_level"
                    required
                    :error="form.errors.reorder_level"
                    hint="The product shows as low stock at or below this figure. Zero means it is not watched."
                >
                    <TextField
                        id="reorder_level"
                        v-model="form.reorder_level"
                        type="number"
                        :step="reorderStep"
                        min="0"
                        :invalid="!!form.errors.reorder_level"
                        placeholder="0"
                    />
                </FormField>

                <FormField v-if="mode === 'edit'" label="On hand">
                    <div
                        class="flex h-11 items-center justify-between rounded-field border border-line bg-surface-sunken px-3.5"
                    >
                        <span class="tabular-nums text-sm font-semibold text-ink">
                            {{ count(onHand) }}
                        </span>
                        <Lock :size="14" class="text-ink-subtle" />
                    </div>
                </FormField>
            </div>
        </section>

        <!-- Status -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Status</h3>

            <div class="mt-4">
                <ToggleSwitch
                    v-model="form.is_active"
                    label="Product is active"
                    description="An inactive product cannot be put on a new purchase or sale. Its history stays."
                />
            </div>

            <p v-if="form.errors.is_active" class="mt-2 text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </section>
    </div>
</template>
