<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import UserFormFields from './UserFormFields.vue'
import { showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

defineProps({
    roles: { type: Array, default: () => [] },
})

const form = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_active: true,
    roles: [],
})

function submit() {
    form.post('/users', {
        onError: (errors) => {
            // Field errors already show under each box. The alert is only for
            // the rules that belong to no single field.
            const loose = Object.entries(errors)
                .filter(([key]) => !['name', 'username', 'email', 'password'].includes(key))
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="New user"
            subheading="Create an account and give it a role."
            back-href="/users"
            back-label="Back to users"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/users">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">
                    Create user
                </PrimaryButton>
            </template>
        </PageHeader>

        <UserFormFields :form="form" :roles="roles" mode="create" />

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/users">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Create user</PrimaryButton>
        </div>
    </form>
</template>
