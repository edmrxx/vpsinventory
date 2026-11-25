<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import PrimaryButton from './PrimaryButton.vue'
import TextField from './TextField.vue'
import { toastSuccess } from '../lib/swal'

/** Cancelling an order sent to the cashier (CR-2): always with a reason. */
const props = defineProps({
    /** The order, or null while closed. */
    order: { type: Object, default: null },
})

const emit = defineEmits(['close', 'done'])

const form = useForm({ reason: '' })
const shown = ref(false)

watch(
    () => props.order,
    (order) => {
        shown.value = !!order
        form.reset()
        form.clearErrors()
    }
)

function submit() {
    form.post(`/pending-payments/${props.order.id}/cancel`, {
        preserveScroll: true,
        onSuccess: (page) => {
            toastSuccess(page.props.flash?.success ?? 'The order was cancelled.')
            emit('done')
        },
    })
}
</script>

<template>
    <Modal
        :open="shown"
        title="Cancel this order"
        :subtitle="order ? `${order.reference} · ${order.customer}` : ''"
        :busy="form.processing"
        size="sm"
        @close="!form.processing && emit('close')"
    >
        <p class="text-sm text-ink-muted">Its goods are free to sell again at once. Say why, for the record.</p>

        <label for="pending-reason" class="mt-4 mb-1.5 block text-sm font-medium text-ink">Reason</label>
        <TextField
            id="pending-reason"
            v-model="form.reason"
            maxlength="255"
            :invalid="!!form.errors.reason"
            placeholder="The customer left without paying"
            @keydown.enter.prevent="submit"
        />
        <p v-if="form.errors.reason" class="mt-1.5 text-xs font-medium text-danger-700">{{ form.errors.reason }}</p>

        <template #footer>
            <PrimaryButton variant="secondary" :disabled="form.processing" @click="emit('close')">Back</PrimaryButton>
            <PrimaryButton variant="danger" :loading="form.processing" @click="submit">Cancel order</PrimaryButton>
        </template>
    </Modal>
</template>
