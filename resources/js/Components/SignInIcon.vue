<script setup>
/**
 * The four drawings on the sign-in pages that no icon set has in the
 * mockup's shape (design/login-mockup.png): the three stacked cubes, the two
 * arrows, the clipboard with two ticks, and the short arrow on Sign in. The
 * rest of the page's icons are Lucide's.
 *
 * Each is drawn edge to edge in its viewBox, so the element's size is the
 * drawing's size and the page can place it to the pixel. The cubes stretch
 * to whatever box they are given (the hero's are wider than the card's) and
 * keep their line width; the others scale as they are.
 */
const props = defineProps({
    name: { type: String, required: true }, // cubes | arrows | clipboard | arrow
    strokeWidth: { type: Number, default: 2.2 },
})

/*
 * Three isometric cubes, two below and one resting on both, with r = 1 the
 * distance from a cube's centre to a corner. Each is its outline and the
 * three edges that meet in the middle of its top face.
 */
const cube = (cx, cy) => {
    const dx = 0.866
    const p = (x, y) => `${(cx + x).toFixed(3)} ${(cy + y).toFixed(3)}`
    return (
        `M${p(0, -1)}L${p(dx, -0.5)}L${p(dx, 0.5)}L${p(0, 1)}L${p(-dx, 0.5)}L${p(-dx, -0.5)}Z` +
        `M${p(0, 0)}L${p(-dx, -0.5)}M${p(0, 0)}L${p(dx, -0.5)}M${p(0, 0)}L${p(0, 1)}`
    )
}
const cubes = cube(1.732, 1) + cube(0.866, 2.5) + cube(2.598, 2.5)

const shapes = {
    cubes: { viewBox: '0 0 3.464 3.5', stretch: true, d: [cubes] },
    arrows: {
        viewBox: '0 0 39 31',
        d: ['M11.5 7.7H37.9', 'M30.4 1.1 37.9 7.7 30.4 14.3', 'M27.5 23.3H1.1', 'M8.6 16.7 1.1 23.3 8.6 29.9'],
    },
    clipboard: {
        viewBox: '0 0 36 42',
        d: [
            'M9.5 4.6H5.1a4 4 0 0 0-4 4v28.3a4 4 0 0 0 4 4h25.8a4 4 0 0 0 4-4V8.6a4 4 0 0 0-4-4h-4.4',
            'M12.5 7.6V4.2a3.1 3.1 0 0 1 3.1-3.1h4.8a3.1 3.1 0 0 1 3.1 3.1v3.4Z',
            'M9 16.4l3.8 3.8 5.6-3.6h8.4',
            'M9 27.4l3.8 3.8 5.6-3.6h8.4',
        ],
    },
    arrow: { viewBox: '0 0 26 18', d: ['M1.3 9H24.4', 'M16.8 1.3 24.4 9 16.8 16.7'] },
}

const shape = shapes[props.name]
</script>

<template>
    <svg
        :viewBox="shape.viewBox"
        :preserveAspectRatio="shape.stretch ? 'none' : 'xMidYMid meet'"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        overflow="visible"
        aria-hidden="true"
        focusable="false"
    >
        <path v-for="d in shape.d" :key="d" :d="d" :vector-effect="shape.stretch ? 'non-scaling-stroke' : undefined" />
    </svg>
</template>
