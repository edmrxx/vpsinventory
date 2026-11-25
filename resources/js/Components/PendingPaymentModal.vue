<script setup>
import { computed } from 'vue'
import RecordModal from './RecordModal.vue'
import PendingPaymentSummary from './PendingPaymentSummary.vue'

/**
 * One order sent to the cashier, read without leaving the list (CR-2).
 * The payload is the controller's own `detail()`, the same the page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    id: { type: [Number, String], default: null },
})

defineEmits(['close', 'cancel', 'receive'])

const url = computed(() => (props.id ? `/pending-payments/${props.id}/preview` : null))
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <PendingPaymentSummary
                :order="data.pending"
                :can="data.can"
                with-full-page
                @cancel="$emit('cancel', $event)"
                @receive="$emit('receive', { order: data.pending, box: data.receive })"
            />
        </template>
    </RecordModal>
</template>
