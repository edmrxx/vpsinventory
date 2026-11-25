<script setup>
/**
 * The developer's credit line: "Designed, Built and Shipped by [logo] DeltaTech".
 *
 * Written here, never in a setting, so it cannot be changed or cleared from
 * the Settings screen. The logo always sits left of the name, and only the
 * name links to the developer's site, in a new tab, so nobody leaves the
 * system by accident.
 *
 * `logo` is chosen by the page for its background:
 *   - gradient: a light background, drawn at 20 px or larger (smaller, its
 *     pale tip fades into the page);
 *   - navy: a light background, at small sizes;
 *   - white: a dark background.
 * A black logo exists for paper, but nothing printed carries this line.
 *
 * Shown on the sign-in pages, the footer of every app page and the
 * signed-out error page. Not on the till, and never on a printed document:
 * those papers go to the client's own customers and suppliers.
 */
import { computed } from 'vue'

const props = defineProps({
    logo: {
        type: String,
        default: 'navy',
        validator: (value) => ['gradient', 'navy', 'white'].includes(value),
    },
    // The logo's size, as classes, so the sign-in page can use its own unit.
    logoClass: { type: String, default: 'size-4' },
})

const SITE = 'https://deltatechph.com'

const source = computed(() => `/images/deltatech/deltatech-${props.logo}`)
</script>

<template>
    <span class="inline-flex flex-wrap items-center justify-center gap-x-1 gap-y-0.5" data-credit>
        <!-- The space between the two keeps "by DeltaTech" apart when the line
             is copied or read aloud; a flex row draws no gap for it. -->
        <span>Designed, Built and Shipped by</span> <span class="inline-flex items-center gap-1 whitespace-nowrap">
            <picture class="contents">
                <source :srcset="`${source}.webp`" type="image/webp" />
                <img
                    :src="`${source}.png`"
                    alt=""
                    width="63"
                    height="64"
                    :class="['shrink-0 object-contain', props.logoClass]"
                    draggable="false"
                    data-credit-logo
                />
            </picture>
            <a
                :href="SITE"
                target="_blank"
                rel="noopener"
                class="font-semibold underline-offset-2 transition-colors hover:underline focus-visible:underline"
            >DeltaTech</a>
        </span>
    </span>
</template>
