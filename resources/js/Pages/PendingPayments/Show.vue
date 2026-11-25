<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PendingPaymentSummary from '../../Components/PendingPaymentSummary.vue'
import PendingCancelModal from '../../Components/PendingCancelModal.vue'
import ReceivePendingPayment from '../../Components/ReceivePendingPayment.vue'

/** One order the kiosk sent to the cashier (CR-2), on a page of its own. */
defineOptions({ layout: AppLayout })

const props = defineProps({
    pending: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    /** The payment box's methods and today, when this person may receive it. */
    receive: { type: Object, default: null },
})

const receiving = ref(null)

const cancelling = ref(null)

function cancelled() {
    cancelling.value = null
    router.reload()
}
</script>

<template>
    <div>
        <PageHeader
            :heading="pending.reference"
            :subheading="`${pending.customer} · ${pending.location}`"
            back-href="/pending-payments"
            back-label="Back to pending payments"
        />

        <section class="card max-w-3xl p-5 sm:p-6">
            <PendingPaymentSummary
                :order="pending"
                :can="can"
                @cancel="cancelling = $event"
                @receive="receiving = props.pending"
            />
        </section>

        <PendingCancelModal :order="cancelling" @close="cancelling = null" @done="cancelled" />
        <ReceivePendingPayment :order="receiving" :box="receive" @close="receiving = null" />
    </div>
</template>
