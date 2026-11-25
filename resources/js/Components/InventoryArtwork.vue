<script setup>
/**
 * The silhouette behind the circle on the sign-in panel.
 *
 * Drawn here rather than loaded as an image, so it takes no request, stays
 * sharp at any size, and follows the text colour it is given.
 */
</script>

<template>
    <svg
        viewBox="0 0 420 320"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        role="presentation"
        aria-hidden="true"
    >
        <g fill="currentColor">
            <!-- Shelf uprights and rails, set back behind the goods. -->
            <g opacity="0.45">
                <rect x="40" y="34" width="11" height="236" rx="3" />
                <rect x="369" y="34" width="11" height="236" rx="3" />
                <rect x="40" y="34" width="340" height="10" rx="3" />
                <rect x="40" y="146" width="340" height="9" rx="3" />
            </g>

            <!-- Upper shelf: three crates, one turned. -->
            <g opacity="0.7">
                <rect x="74" y="78" width="76" height="68" rx="5" />
                <rect x="74" y="104" width="76" height="7" opacity="0.45" />

                <rect x="162" y="62" width="88" height="84" rx="5" />
                <rect x="162" y="94" width="88" height="8" opacity="0.45" />
                <rect x="200" y="62" width="12" height="84" opacity="0.3" />

                <rect x="262" y="90" width="72" height="56" rx="5" />
                <rect x="262" y="112" width="72" height="7" opacity="0.45" />
            </g>

            <!-- Lower shelf: a tall stack and a pair of boxes. -->
            <g opacity="0.85">
                <rect x="70" y="176" width="96" height="78" rx="6" />
                <rect x="70" y="208" width="96" height="9" opacity="0.4" />
                <rect x="110" y="176" width="14" height="78" opacity="0.28" />

                <rect x="182" y="198" width="78" height="56" rx="5" />
                <rect x="182" y="220" width="78" height="7" opacity="0.4" />

                <rect x="276" y="166" width="68" height="88" rx="5" />
                <rect x="276" y="202" width="68" height="8" opacity="0.4" />
            </g>

            <!-- Pallet on the floor. -->
            <g opacity="0.55">
                <rect x="58" y="262" width="300" height="12" rx="4" />
                <rect x="72" y="278" width="30" height="16" rx="3" />
                <rect x="193" y="278" width="30" height="16" rx="3" />
                <rect x="314" y="278" width="30" height="16" rx="3" />
            </g>
        </g>
    </svg>
</template>
