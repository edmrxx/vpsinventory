<script setup>
import OrderSlip from './OrderSlip.vue'
import SaleSlip from './SaleSlip.vue'
import { slipOnPaper } from '../lib/slip'

/**
 * Where a printed slip lives while it is printed.
 *
 * Sent to the end of `<body>`, beside the app rather than inside it, so the
 * print rules in `app.css` can hide everything else on paper with one
 * selector. Never shown on screen. Put once in each shell: `AppLayout`, and
 * the till, which has no layout.
 */
</script>

<template>
    <Teleport to="body">
        <div v-if="slipOnPaper" id="sale-slip" aria-hidden="true">
            <!-- An order sent to the cashier (CR-2) prints as NOT PAID. -->
            <OrderSlip v-if="slipOnPaper.__order" :order="slipOnPaper" />
            <SaleSlip v-else :sale="slipOnPaper" />
        </div>
    </Teleport>
</template>
