<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import UserFormFields from './UserFormFields.vue'
import { showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    user: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    isSelf: { type: Boolean, default: false },
})

const form = useForm({
    name: props.user.name,
    username: props.user.username,
    email: props.user.email ?? '',
    password: '',
    password_confirmation: '',
    is_active: props.user.is_active,
    roles: [...props.user.roles],
})

function submit() {
    form.put(`/users/${props.user.id}`, {
        onError: (errors) => {
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
            :heading="`Edit ${user.name}`"
            :subheading="`@${user.username}`"
            back-href="/users"
            back-label="Back to users"
        >
            <template #actions>
                <PrimaryButton variant="secondary" :href="`/users/${user.id}`">
                    View
                </PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">
                    Save changes
                </PrimaryButton>
            </template>
        </PageHeader>

        <UserFormFields :form="form" :roles="roles" mode="edit" :is-self="isSelf" />

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/users">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save changes</PrimaryButton>
        </div>
    </form>
</template>
