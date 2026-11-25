<script setup>
import { computed, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Lock, RotateCcw, Save, Wrench } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import SettingField from '../../Components/SettingField.vue'
import { icon } from '../../lib/icons'
import { confirmAction, showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    groups: { type: Array, default: () => [] },
    group: { type: Object, required: true },
    fields: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const form = useForm({ values: { ...props.values } })

// Every group uses this same page, so Inertia swaps the props instead of
// mounting again. The form has to be started afresh by hand.
watch(
    () => props.group.key,
    () => {
        form.defaults({ values: { ...props.values } })
        form.reset()
        form.clearErrors()
    }
)

const readOnly = computed(() => !props.can.update)

const fullWidth = (field) => field.width === 'full' || field.type === 'textarea'

function submit() {
    form.put(`/settings/${props.group.key}`, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ values: { ...form.values } }),
        onError: (errors) => {
            // Field errors show under each box already; this catches the rest.
            const loose = Object.entries(errors)
                .filter(([key]) => !key.startsWith('values.'))
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}

async function resetGroup() {
    const agreed = await confirmAction({
        icon: 'warning',
        title: `Put ${props.group.label} back to the defaults?`,
        text: 'Every value in this group returns to how it started.',
        confirmText: 'Yes, put it back',
    })

    if (!agreed) return

    router.delete(`/settings/${props.group.key}`, { preserveScroll: true })
}

/** The error for one field, if the server sent one. */
const errorFor = (name) => form.errors[`values.${name}`]
</script>

<template>
    <div>
        <PageHeader
            heading="Settings"
            subheading="Everything an administrator can change without touching the code."
        >
            <template #actions>
                <span
                    v-if="readOnly"
                    class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-3 py-1.5 text-2xs font-semibold tracking-wide text-ink-muted uppercase"
                >
                    <Lock :size="12" />
                    Read only
                </span>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[15rem_1fr]">
            <!-- Group list -->
            <nav class="card h-fit p-2" aria-label="Settings groups">
                <ul class="space-y-1">
                    <li v-for="item in groups" :key="item.key">
                        <Link
                            :href="`/settings/${item.key}`"
                            class="flex items-start gap-3 rounded-field px-3 py-2.5 transition-colors"
                            :class="
                                item.key === group.key
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'
                            "
                            :aria-current="item.key === group.key ? 'page' : undefined"
                        >
                            <component
                                :is="icon(item.icon)"
                                :size="17"
                                class="mt-0.5 shrink-0"
                                :class="item.key === group.key ? 'text-brand-600' : ''"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium">
                                    {{ item.label }}
                                </span>
                                <span class="block text-2xs text-ink-subtle">
                                    {{
                                        item.field_count === 0
                                            ? 'No fields yet'
                                            : `${item.field_count} ${item.field_count === 1 ? 'setting' : 'settings'}`
                                    }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <!-- The chosen group -->
            <form @submit.prevent="submit">
                <section class="card p-5 sm:p-6">
                    <div class="flex items-start gap-3 border-b border-line pb-5">
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-field bg-brand-50 text-brand-700"
                        >
                            <component :is="icon(group.icon)" :size="19" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-ink">{{ group.label }}</h3>
                            <p v-if="group.description" class="mt-0.5 text-sm text-ink-muted">
                                {{ group.description }}
                            </p>
                        </div>
                    </div>

                    <!-- A group with no fields yet. -->
                    <div
                        v-if="fields.length === 0"
                        class="flex flex-col items-center py-12 text-center"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-full bg-surface-sunken text-ink-subtle"
                        >
                            <Wrench :size="22" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink">Ready for its fields</p>
                        <p class="mt-1 max-w-sm text-xs text-ink-subtle">
                            This group is registered but holds nothing yet. Add fields under
                            <code class="rounded bg-surface-sunken px-1 py-0.5 font-mono"
                                >groups.{{ group.key }}.fields</code
                            >
                            in
                            <code class="rounded bg-surface-sunken px-1 py-0.5 font-mono"
                                >config/settings.php</code
                            >
                            and they appear here, validated and saved, with no other change.
                        </p>
                    </div>

                    <!-- The fields. -->
                    <div v-else class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div
                            v-for="field in fields"
                            :key="field.key"
                            :class="fullWidth(field) ? 'sm:col-span-2' : ''"
                        >
                            <SettingField
                                :field="field"
                                :model-value="form.values[field.name]"
                                :error="errorFor(field.name)"
                                :disabled="readOnly"
                                @update:model-value="form.values[field.name] = $event"
                            />
                        </div>
                    </div>
                </section>

                <div
                    v-if="fields.length > 0 && !readOnly"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-muted transition-colors hover:text-danger-700"
                        @click="resetGroup"
                    >
                        <RotateCcw :size="14" />
                        Put back to the defaults
                    </button>

                    <div class="flex items-center gap-2">
                        <span v-if="form.isDirty" class="text-xs text-warning-700">
                            Not saved yet
                        </span>
                        <PrimaryButton
                            type="submit"
                            :loading="form.processing"
                            :disabled="!form.isDirty"
                        >
                            <Save :size="15" />
                            Save changes
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>
