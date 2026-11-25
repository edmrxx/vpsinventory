<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { isActive } from '../navigation'

const props = defineProps({
    item: { type: Object, required: true },
    iconsOnly: { type: Boolean, default: false },
})

const page = usePage()

const active = computed(() => isActive(page.component, props.item.component))

/**
 * A red count beside the label while something waits there — orders sent
 * to the cashier — read from the shared `badges` by the item's `badge` key.
 * Nothing shows at zero.
 */
const badge = computed(() => {
    const value = props.item.badge ? Number(page.props.badges?.[props.item.badge] ?? 0) : 0

    return value > 0 ? (value > 99 ? '99+' : String(value)) : null
})
</script>

<template>
    <component
        :is="item.disabled ? 'span' : Link"
        :href="item.disabled ? undefined : item.href"
        :aria-current="active ? 'page' : undefined"
        :aria-disabled="item.disabled ? 'true' : undefined"
        :title="iconsOnly ? item.label : undefined"
        class="group relative flex items-center gap-3 rounded-field px-3 py-2.5 text-sm font-medium transition-colors duration-150 outline-none"
        :class="[
            active
                ? 'bg-brand-50 text-brand-700'
                : item.disabled
                  ? 'cursor-not-allowed text-ink-subtle'
                  : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
            iconsOnly ? 'justify-center px-0' : '',
        ]"
    >
        <!-- The bar that marks the open page. -->
        <span
            v-if="active"
            class="absolute top-1/2 -left-3 h-6 w-1 -translate-y-1/2 rounded-r-full bg-brand-600"
            aria-hidden="true"
        />

        <component
            :is="item.icon"
            :size="18"
            :stroke-width="active ? 2.25 : 1.9"
            class="shrink-0 transition-colors"
            :class="active ? 'text-brand-600' : ''"
            aria-hidden="true"
        />

        <span v-if="!iconsOnly" class="truncate">{{ item.label }}</span>

        <span
            v-if="badge && !iconsOnly"
            class="ml-auto min-w-5 rounded-full bg-danger-500 px-1.5 py-0.5 text-center text-2xs leading-none font-bold text-white tabular-nums"
            :aria-label="`${badge} waiting`"
            data-nav-badge
        >
            {{ badge }}
        </span>
        <!-- Folded menu: a red dot with the count on the icon's corner. -->
        <span
            v-if="badge && iconsOnly"
            class="absolute top-1 right-1 min-w-4 rounded-full bg-danger-500 px-1 text-center text-[10px] leading-4 font-bold text-white tabular-nums"
            :aria-label="`${badge} waiting`"
            data-nav-badge
        >
            {{ badge }}
        </span>

        <span
            v-if="!iconsOnly && item.disabled"
            class="ml-auto rounded-full bg-surface-sunken px-2 py-0.5 text-2xs font-semibold tracking-wide text-ink-subtle uppercase"
        >
            Soon
        </span>
    </component>
</template>
