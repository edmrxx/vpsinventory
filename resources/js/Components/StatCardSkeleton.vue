<script setup>
import Skeleton from './Skeleton.vue'

defineProps({
    count: { type: Number, default: 4 },
})
</script>

<template>
    <div
        v-for="n in count"
        :key="n"
        class="card p-5"
        aria-busy="true"
    >
        <div class="flex items-start justify-between gap-3">
            <Skeleton width="w-24" height="h-3" />
            <Skeleton width="w-9" height="h-9" shape="circle" />
        </div>
        <Skeleton width="w-28" height="h-8" class="mt-4" />
        <Skeleton width="w-20" height="h-3" class="mt-3" />
    </div>
</template>
