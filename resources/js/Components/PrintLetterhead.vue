<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The head of every full-page document (phase 35): who it is from on the
 * left, what it is on the right.
 *
 * One copy for the quotation, the purchase order, the statement, Aging and
 * Reports, so Settings → Print Layouts reaches all of them: "Print the
 * logo" and "Print the address, TIN and phone". The right side is the
 * page's own — its title, its reference, a Cancelled stamp — through the
 * default slot under the title.
 */
defineProps({
    /** "Quotation", "Purchase order", "Statement of account"… */
    title: { type: String, required: true },
})

const page = usePage()
const company = computed(() => page.props.company ?? {})
const printing = computed(() => page.props.printing ?? {})

const showLogo = computed(() => printing.value.show_logo !== false && Boolean(company.value.logo))
const showDetails = computed(() => printing.value.show_company_details !== false)
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-6">
        <div class="flex items-start gap-4">
            <img v-if="showLogo" :src="company.logo" alt="" class="size-14 rounded-field object-contain" />
            <div>
                <p class="text-base font-bold text-ink">{{ company.name }}</p>
                <template v-if="showDetails">
                    <p v-if="company.legal_name && company.legal_name !== company.name" class="text-xs text-ink-muted">{{ company.legal_name }}</p>
                    <p v-if="company.address" class="text-xs whitespace-pre-line text-ink-muted">{{ company.address }}</p>
                    <p class="text-xs text-ink-muted">
                        <span v-if="company.tin">TIN {{ company.tin }}</span>
                        <span v-if="company.tin && company.phone"> · </span>
                        <span v-if="company.phone">{{ company.phone }}</span>
                    </p>
                </template>
            </div>
        </div>
        <div class="text-right">
            <p class="text-lg font-bold tracking-tight text-ink uppercase">{{ title }}</p>
            <slot />
        </div>
    </header>
</template>
