<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import FormField from '../../Components/FormField.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import TextField from '../../Components/TextField.vue'
import PermissionPicker from './PermissionPicker.vue'
import { showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

defineProps({
    groups: { type: Array, default: () => [] },
})

const form = useForm({
    name: '',
    permissions: [],
})

function submit() {
    form.post('/roles', {
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
            heading="New role"
            subheading="Name the role, then choose what it may do."
            back-href="/roles"
            back-label="Back to roles"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/roles">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">
                    Create role
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
                    hint="For example: Cashier, Branch Manager, Auditor."
                >
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="Cashier"
                    />
                </FormField>
            </section>

            <section class="card p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-ink">Permissions</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    Pick what this role may do. You can change it later.
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
            <PrimaryButton type="submit" :loading="form.processing">Create role</PrimaryButton>
        </div>
    </form>
</template>
