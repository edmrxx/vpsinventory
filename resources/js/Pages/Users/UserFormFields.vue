<script setup>
import { ref } from 'vue'
import { Eye, EyeOff } from '@lucide/vue'
import FormField from '../../Components/FormField.vue'
import TextField from '../../Components/TextField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

/**
 * The fields shared by the new-user and edit-user forms.
 *
 * `form` is an Inertia useForm object, so it is already reactive and carries
 * its own errors.
 */
const props = defineProps({
    form: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    /** "create" | "edit" */
    mode: { type: String, default: 'create' },
    /** True when the signed-in user is editing their own account. */
    isSelf: { type: Boolean, default: false },
})

const showPassword = ref(false)

function toggleRole(name) {
    const roles = [...props.form.roles]
    const index = roles.indexOf(name)

    if (index === -1) {
        roles.push(name)
    } else {
        roles.splice(index, 1)
    }

    props.form.roles = roles
}
</script>

<template>
    <div class="space-y-6">
        <!-- Account -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Account</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                The name shown in the app, and the username used to sign in.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Full name"
                    for="name"
                    required
                    :error="form.errors.name"
                >
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="Juan dela Cruz"
                    />
                </FormField>

                <FormField
                    label="Username"
                    for="username"
                    required
                    :error="form.errors.username"
                    hint="Letters, numbers, dots, dashes and underscores."
                >
                    <TextField
                        id="username"
                        v-model="form.username"
                        :invalid="!!form.errors.username"
                        placeholder="jdelacruz"
                    />
                </FormField>

                <FormField
                    label="Email"
                    for="email"
                    :error="form.errors.email"
                    hint="Optional. Not used to sign in."
                    class="sm:col-span-2"
                >
                    <TextField
                        id="email"
                        v-model="form.email"
                        type="email"
                        :invalid="!!form.errors.email"
                        placeholder="juan@example.com"
                    />
                </FormField>
            </div>
        </section>

        <!-- Password -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Password</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                {{
                    mode === 'edit'
                        ? 'Leave both boxes empty to keep the current password.'
                        : 'At least 8 characters.'
                }}
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Password"
                    for="password"
                    :required="mode === 'create'"
                    :error="form.errors.password"
                >
                    <div class="relative">
                        <TextField
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            :invalid="!!form.errors.password"
                            placeholder="••••••••"
                        />
                        <button
                            type="button"
                            class="absolute top-1/2 right-1.5 grid size-8 -translate-y-1/2 place-items-center rounded-md text-ink-subtle transition-colors hover:bg-surface-sunken hover:text-ink-muted"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            @click="showPassword = !showPassword"
                        >
                            <component :is="showPassword ? EyeOff : Eye" :size="16" />
                        </button>
                    </div>
                </FormField>

                <FormField
                    label="Repeat password"
                    for="password_confirmation"
                    :required="mode === 'create'"
                >
                    <TextField
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="••••••••"
                    />
                </FormField>
            </div>
        </section>

        <!-- Roles -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Roles</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                A role carries the permissions. A user may hold more than one.
            </p>

            <p v-if="form.errors.roles" class="mt-3 text-xs font-medium text-danger-700">
                {{ form.errors.roles }}
            </p>

            <div class="mt-5 grid gap-2 sm:grid-cols-2">
                <label
                    v-for="role in roles"
                    :key="role.name"
                    class="flex cursor-pointer items-start gap-3 rounded-field border p-3.5 transition-colors"
                    :class="
                        form.roles.includes(role.name)
                            ? 'border-brand-300 bg-brand-50'
                            : 'border-line bg-surface-muted hover:bg-surface-sunken'
                    "
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-line-strong accent-brand-600"
                        :checked="form.roles.includes(role.name)"
                        @change="toggleRole(role.name)"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink">{{ role.label }}</span>
                        <span class="block text-xs text-ink-subtle">
                            {{ role.users_count }}
                            {{ role.users_count === 1 ? 'user' : 'users' }}
                        </span>
                    </span>
                </label>

                <p v-if="roles.length === 0" class="text-sm text-ink-subtle">
                    No roles exist yet.
                </p>
            </div>
        </section>

        <!-- Status -->
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Status</h3>

            <div class="mt-4">
                <ToggleSwitch
                    v-model="form.is_active"
                    label="Account is active"
                    :description="
                        isSelf
                            ? 'You cannot deactivate your own account.'
                            : 'An inactive account cannot sign in.'
                    "
                    :disabled="isSelf"
                />
            </div>

            <p v-if="form.errors.is_active" class="mt-2 text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </section>
    </div>
</template>
