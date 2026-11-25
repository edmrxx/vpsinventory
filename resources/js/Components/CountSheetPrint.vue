<script setup>
import PrintLetterhead from './PrintLetterhead.vue'

/**
 * A count sheet on paper, for counting by hand (phase 32).
 *
 * It never prints a book figure, whoever prints it — the approver's payload
 * carries one — because a counter who can read what the books expect
 * writes that down instead of what is on the shelf. The counted column is
 * left blank on purpose. `tests/js/CountSheetPrint.test.js` guards both.
 */
defineProps({
    count: { type: Object, required: true },
})
</script>

<template>
    <article>
        <PrintLetterhead title="Count sheet">
            <p class="font-mono text-sm font-semibold text-ink">{{ count.reference }}</p>
        </PrintLetterhead>

        <dl class="grid grid-cols-3 gap-4 border-b border-line py-4 text-xs">
            <div>
                <dt class="text-ink-muted">Location</dt>
                <dd class="font-semibold text-ink">{{ count.location }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Counting</dt>
                <dd class="font-semibold text-ink">{{ count.scope }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Cutoff</dt>
                <dd class="font-semibold text-ink">{{ count.cutoff_at }}</dd>
            </div>
        </dl>

        <p class="mt-3 text-2xs text-ink-muted">
            Count everything at this location, goods set aside for a loss report too. Leave a line
            empty only if it was not counted; write 0 for an empty shelf.
        </p>

        <table class="mt-4 w-full text-xs">
            <thead>
                <tr class="border-b border-line-strong text-left text-ink-muted">
                    <th class="py-1.5 pr-3 font-semibold">SKU</th>
                    <th class="py-1.5 pr-3 font-semibold">Product</th>
                    <th class="py-1.5 pr-3 font-semibold">Unit</th>
                    <th class="w-32 py-1.5 font-semibold">Counted</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="line in count.lines" :key="line.id" class="break-inside-avoid border-b border-line">
                    <td class="py-2 pr-3 font-mono">{{ line.sku }}</td>
                    <td class="py-2 pr-3">{{ line.name }}</td>
                    <td class="py-2 pr-3">{{ line.unit }}</td>
                    <td class="py-2"><span class="block border-b border-ink-subtle">&nbsp;</span></td>
                </tr>
            </tbody>
        </table>

        <div class="mt-10 grid grid-cols-2 gap-10 text-xs break-inside-avoid">
            <div>
                <div class="h-10 border-b border-ink"></div>
                <p class="mt-1 text-ink-muted">Counted by</p>
            </div>
            <div>
                <div class="h-10 border-b border-ink"></div>
                <p class="mt-1 text-ink-muted">Checked by</p>
            </div>
        </div>
    </article>
</template>
