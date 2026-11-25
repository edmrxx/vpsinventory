<script setup>
import { router } from '@inertiajs/vue3'
import { Lock, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import TableAction from '../../Components/TableAction.vue'
import { confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

defineProps({
    roles: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'name', label: 'Role', sortable: true },
    { key: 'permissions_count', label: 'Permissions', sortable: true, align: 'center' },
    { key: 'users_count', label: 'Users', sortable: true, align: 'center' },
    { key: 'created_at', label: 'Added', sortable: true, hideOnMobile: true },
    { key: 'actions', label: 'Actions', align: 'right', width: '120px' },
]

async function removeRole(role) {
    const agreed = await confirmDelete({
        title: 'Delete this role?',
        name: role.name,
        text: `The [${role.name}] role will be removed. You cannot undo this.`,
    })

    if (!agreed) return

    router.delete(`/roles/${role.id}`, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The role was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Roles & Permissions"
            subheading="A role is a set of permissions. Add as many as you need."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/roles/create">
                    <Plus :size="16" />
                    New role
                </PrimaryButton>
            </template>
        </PageHeader>

        <DataTable
            :data="roles"
            :columns="columns"
            :filters="filters"
            url="/roles"
            :only="['roles', 'filters']"
            search-placeholder="Search role name…"
            empty-title="No roles yet"
            empty-text="Add the first role to group permissions."
        >
            <template #cell-name="{ row }">
                <div class="flex items-center justify-end gap-2 md:justify-start">
                    <span class="font-medium text-ink">{{ row.name }}</span>
                    <span
                        v-if="row.is_protected"
                        class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-2xs font-semibold tracking-wide text-ink-muted uppercase"
                        title="Built in. It cannot be renamed or deleted."
                    >
                        <Lock :size="10" />
                        Built in
                    </span>
                </div>
            </template>

            <template #cell-permissions_count="{ row }">
                <span
                    class="inline-flex min-w-8 justify-center rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700"
                >
                    {{ row.is_admin ? 'All' : row.permissions_count }}
                </span>
            </template>

            <template #cell-users_count="{ row }">
                <span class="text-sm text-ink-muted">{{ row.users_count }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" :href="`/roles/${row.id}`" label="View role" />

                    <TableAction
                        variant="edit"
                        :href="row.can.update ? `/roles/${row.id}/edit` : null"
                        :disabled="!row.can.update"
                        :label="
                            row.is_admin
                                ? 'The administrator role cannot be edited'
                                : 'Edit role'
                        "
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        :label="
                            row.is_protected
                                ? 'Built-in roles cannot be deleted'
                                : row.users_count > 0
                                  ? 'Move the users off this role first'
                                  : 'Delete role'
                        "
                        @click="removeRole(row)"
                    />
                </div>
            </template>
        </DataTable>
    </div>
</template>
