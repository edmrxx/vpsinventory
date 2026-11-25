<script setup>
import { router } from '@inertiajs/vue3'
import { Plus, Power } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'username', label: 'Username', sortable: true },
    { key: 'email', label: 'Email', sortable: true, hideOnMobile: true },
    { key: 'roles', label: 'Roles' },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center' },
    { key: 'created_at', label: 'Added', sortable: true, hideOnMobile: true },
    { key: 'actions', label: 'Actions', align: 'right', width: '140px' },
]

async function removeUser(user) {
    if (!(await confirmDelete({ name: user.name }))) return

    router.delete(`/users/${user.id}`, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The user was not deleted.'),
    })
}

async function toggleStatus(user) {
    const turningOff = user.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this user?' : 'Activate this user?',
        text: turningOff
            ? `${user.name} will not be able to log in.`
            : `${user.name} will be able to log in again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/users/${user.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Users"
            subheading="Accounts that can sign in, and the roles they hold."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/users/create">
                    <Plus :size="16" />
                    New user
                </PrimaryButton>
            </template>
        </PageHeader>

        <DataTable
            :data="users"
            :columns="columns"
            :filters="filters"
            url="/users"
            :only="['users', 'filters']"
            search-placeholder="Search name, username, email or role…"
            empty-title="No users yet"
            empty-text="Add the first account to get started."
        >
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-2xs font-bold text-brand-700"
                    >
                        {{ row.name.charAt(0).toUpperCase() }}
                    </span>
                    <span class="min-w-0 font-medium text-ink">{{ row.name }}</span>
                </div>
            </template>

            <template #cell-username="{ row }">
                <span class="font-mono text-xs text-ink-muted">@{{ row.username }}</span>
            </template>

            <template #cell-email="{ row }">
                <span class="text-ink-muted">{{ row.email ?? '—' }}</span>
            </template>

            <template #cell-roles="{ row }">
                <div class="flex flex-wrap justify-end gap-1 md:justify-start">
                    <span
                        v-for="role in row.roles"
                        :key="role"
                        class="rounded-full bg-surface-sunken px-2 py-0.5 text-2xs font-semibold text-ink-muted"
                    >
                        {{ role }}
                    </span>
                    <span v-if="row.roles.length === 0" class="text-xs text-ink-subtle">
                        No role
                    </span>
                </div>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" :href="`/users/${row.id}`" label="View user" />

                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate user' : 'Activate user'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :href="row.can.update ? `/users/${row.id}/edit` : null"
                        :disabled="!row.can.update"
                        label="Edit user"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete user"
                        @click="removeUser(row)"
                    />
                </div>
            </template>
        </DataTable>
    </div>
</template>
