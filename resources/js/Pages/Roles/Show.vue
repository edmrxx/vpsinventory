<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { Check, Lock, Pencil, Trash2, X } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import { confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    role: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
})

const held = computed(() => new Set(props.role.permissions))

async function removeRole() {
    if (!(await confirmDelete({ title: 'Delete this role?', name: props.role.name }))) return

    router.delete(`/roles/${props.role.id}`, {
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The role was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="role.name"
            :subheading="`${role.permissions.length} permissions · ${role.users_count} ${role.users_count === 1 ? 'user' : 'users'}`"
            back-href="/roles"
            back-label="Back to roles"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.update"
                    variant="secondary"
                    :href="`/roles/${role.id}/edit`"
                >
                    <Pencil :size="15" />
                    Edit
                </PrimaryButton>
                <PrimaryButton v-if="can.delete" variant="danger" @click="removeRole">
                    <Trash2 :size="15" />
                    Delete
                </PrimaryButton>
            </template>
        </PageHeader>

        <p
            v-if="role.is_admin"
            class="mb-4 flex items-start gap-2 rounded-card border border-warning-500/25 bg-warning-50 px-4 py-3 text-sm text-warning-700"
        >
            <Lock :size="16" class="mt-0.5 shrink-0" />
            This is the administrator role. It always holds every permission, including any
            permission added later, so it cannot be edited.
        </p>

        <div class="space-y-4">
            <section
                v-for="group in groups"
                :key="group.key"
                class="card overflow-hidden"
            >
                <div class="border-b border-line bg-surface-muted px-4 py-3">
                    <p class="text-sm font-semibold text-ink">{{ group.label }}</p>
                    <p v-if="group.description" class="mt-0.5 text-xs text-ink-subtle">
                        {{ group.description }}
                    </p>
                </div>

                <ul class="divide-y divide-line">
                    <li
                        v-for="permission in group.permissions"
                        :key="permission.name"
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <span class="min-w-0">
                            <span class="block text-sm text-ink">{{ permission.label }}</span>
                            <span class="block font-mono text-2xs text-ink-subtle">
                                {{ permission.name }}
                            </span>
                        </span>

                        <span
                            class="grid size-6 shrink-0 place-items-center rounded-full"
                            :class="
                                held.has(permission.name)
                                    ? 'bg-success-50 text-success-700'
                                    : 'bg-surface-sunken text-ink-subtle'
                            "
                            :title="held.has(permission.name) ? 'Allowed' : 'Not allowed'"
                        >
                            <Check v-if="held.has(permission.name)" :size="13" :stroke-width="3" />
                            <X v-else :size="13" />
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
