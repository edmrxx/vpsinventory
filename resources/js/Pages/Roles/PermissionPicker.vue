<script setup>
import { computed } from 'vue'
import { Check, Minus } from '@lucide/vue'

/**
 * The permission chooser on the role form.
 *
 * Permissions arrive grouped by module, straight from
 * config/permissions.php, so a new module appears here with no change to
 * this component.
 */
const props = defineProps({
    groups: { type: Array, required: true },
    /** The chosen permission names. */
    modelValue: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const chosen = computed(() => new Set(props.modelValue))

const allNames = computed(() =>
    props.groups.flatMap((group) => group.permissions.map((permission) => permission.name))
)

const groupState = (group) => {
    const names = group.permissions.map((permission) => permission.name)
    const picked = names.filter((name) => chosen.value.has(name)).length

    if (picked === 0) return 'none'

    return picked === names.length ? 'all' : 'some'
}

function toggle(name) {
    if (props.disabled) return

    const next = new Set(chosen.value)
    next.has(name) ? next.delete(name) : next.add(name)

    emit('update:modelValue', [...next])
}

function toggleGroup(group) {
    if (props.disabled) return

    const names = group.permissions.map((permission) => permission.name)
    const next = new Set(chosen.value)

    if (groupState(group) === 'all') {
        names.forEach((name) => next.delete(name))
    } else {
        names.forEach((name) => next.add(name))
    }

    emit('update:modelValue', [...next])
}

function toggleAll() {
    if (props.disabled) return

    emit(
        'update:modelValue',
        props.modelValue.length === allNames.value.length ? [] : [...allNames.value]
    )
}

const allChosen = computed(() => props.modelValue.length === allNames.value.length)
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-ink-muted">
                <span class="font-semibold text-ink">{{ modelValue.length }}</span>
                of {{ allNames.length }} permissions chosen
            </p>

            <button
                v-if="!disabled"
                type="button"
                class="text-xs font-semibold text-brand-700 transition-colors hover:underline"
                @click="toggleAll"
            >
                {{ allChosen ? 'Clear all' : 'Choose all' }}
            </button>
        </div>

        <div
            v-for="group in groups"
            :key="group.key"
            class="overflow-hidden rounded-card border border-line"
        >
            <!-- Group header -->
            <div
                class="flex items-start justify-between gap-3 border-b border-line bg-surface-muted px-4 py-3"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-ink">{{ group.label }}</p>
                    <p v-if="group.description" class="mt-0.5 text-xs text-ink-subtle">
                        {{ group.description }}
                    </p>
                </div>

                <button
                    type="button"
                    :disabled="disabled"
                    class="flex shrink-0 items-center gap-1.5 rounded-field px-2.5 py-1.5 text-2xs font-semibold tracking-wide uppercase transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                    :class="
                        groupState(group) === 'all'
                            ? 'bg-brand-600 text-ink-inverse'
                            : groupState(group) === 'some'
                              ? 'bg-brand-50 text-brand-700'
                              : 'bg-surface text-ink-muted ring-1 ring-line-strong ring-inset hover:bg-surface-sunken'
                    "
                    @click="toggleGroup(group)"
                >
                    <Check v-if="groupState(group) === 'all'" :size="12" />
                    <Minus v-else-if="groupState(group) === 'some'" :size="12" />
                    {{ groupState(group) === 'all' ? 'All' : 'Select all' }}
                </button>
            </div>

            <!-- Permissions -->
            <div class="grid gap-px bg-line sm:grid-cols-2">
                <label
                    v-for="permission in group.permissions"
                    :key="permission.name"
                    class="flex items-start gap-3 bg-surface px-4 py-3 transition-colors"
                    :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-surface-muted'"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0 rounded border-line-strong accent-brand-600"
                        :checked="chosen.has(permission.name)"
                        :disabled="disabled"
                        @change="toggle(permission.name)"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm text-ink">{{ permission.label }}</span>
                        <span class="block font-mono text-2xs text-ink-subtle">
                            {{ permission.name }}
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>
</template>
