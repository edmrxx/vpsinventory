<script setup>
import { useForm } from '@inertiajs/vue3'
import { Lock } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import FormField from '../../Components/FormField.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import TextField from '../../Components/TextField.vue'
import PermissionPicker from './PermissionPicker.vue'
import { showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    role: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
})

const form = useForm({
    name: props.role.name,
    permissions: [...props.role.permissions],
})

function submit() {
    form.put(`/roles/${props.role.id}`, {
        onError: (errors) => {
            const loose = Object.entries(errors)
                .filter(([key]) => key !== 'name')
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            :heading="`Edit ${role.name}`"
            :subheading="`${role.users_count} ${role.users_count === 1 ? 'user holds' : 'users hold'} this role`"
            back-href="/roles"
            back-label="Back to roles"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/roles">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">
                    Save changes
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <FormField
                    label="Role name"
                    for="name"
                    required
                    :error="form.errors.name"
                    :hint="
                        role.is_protected
                            ? 'This role is built in, so its name cannot change.'
                            : null
                    "
                >
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        :disabled="role.is_protected"
                    />
                </FormField>

                <p
                    v-if="role.is_protected"
                    class="mt-4 flex items-start gap-2 rounded-field bg-warning-50 px-3.5 py-2.5 text-xs text-warning-700"
                >
                    <Lock :size="14" class="mt-0.5 shrink-0" />
                    This role is built in. You may change its permissions, but not its name,
                    and it cannot be deleted.
                </p>
            </section>

            <section class="card p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-ink">Permissions</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    A change here reaches every user holding this role at once.
                </p>

                <p v-if="form.errors.permissions" class="mt-3 text-xs font-medium text-danger-700">
                    {{ form.errors.permissions }}
                </p>

                <div class="mt-5">
                    <PermissionPicker v-model="form.permissions" :groups="groups" />
                </div>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/roles">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save changes</PrimaryButton>
        </div>
    </form>
</template>
