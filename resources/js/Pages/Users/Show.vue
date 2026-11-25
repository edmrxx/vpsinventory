<script setup>
import { router } from '@inertiajs/vue3'
import { Pencil, Trash2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import { confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    user: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const details = [
    { label: 'Username', value: `@${props.user.username}` },
    { label: 'Email', value: props.user.email ?? '—' },
    { label: 'Added', value: props.user.created_at ?? '—' },
    { label: 'Last changed', value: props.user.updated_at ?? '—' },
]

async function removeUser() {
    if (!(await confirmDelete({ name: props.user.name }))) return

    router.delete(`/users/${props.user.id}`, {
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The user was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="user.name"
            :subheading="`@${user.username}`"
            back-href="/users"
            back-label="Back to users"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.update"
                    variant="secondary"
                    :href="`/users/${user.id}/edit`"
                >
                    <Pencil :size="15" />
                    Edit
                </PrimaryButton>
                <PrimaryButton v-if="can.delete" variant="danger" @click="removeUser">
                    <Trash2 :size="15" />
                    Delete
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Profile -->
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-base font-bold text-ink-inverse shadow-brand"
                    >
                        {{ user.initials }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-ink">{{ user.name }}</p>
                        <div class="mt-1.5">
                            <StatusPill :active="user.is_active" />
                        </div>
                    </div>
                </div>

                <dl class="mt-6 space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Roles and permissions -->
            <section class="card p-5 sm:p-6 lg:col-span-2">
                <h3 class="text-sm font-semibold text-ink">Roles</h3>

                <div class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="role in user.roles"
                        :key="role"
                        class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 ring-inset"
                    >
                        {{ role }}
                    </span>
                    <p v-if="user.roles.length === 0" class="text-sm text-ink-subtle">
                        This user holds no role, so it can sign in but do nothing.
                    </p>
                </div>

                <h3 class="mt-7 text-sm font-semibold text-ink">
                    Permissions
                    <span class="ml-1 font-normal text-ink-subtle">
                        ({{ user.permissions.length }})
                    </span>
                </h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    Everything the roles above allow.
                </p>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span
                        v-for="permission in user.permissions"
                        :key="permission"
                        class="rounded-md bg-surface-sunken px-2 py-1 font-mono text-2xs text-ink-muted"
                    >
                        {{ permission }}
                    </span>
                    <p v-if="user.permissions.length === 0" class="text-sm text-ink-subtle">
                        None.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
