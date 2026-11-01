<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{ email: string; expiresAt: string; token: string }>();

const form = useForm({ name: '', password: '', password_confirmation: '' });

function submit(): void {
    form.post(`/invitations/${props.token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AppLayout title="Create your account">
        <p class="mb-6 text-slate-700">
            You were invited as <strong>{{ email }}</strong>. The invitation expires {{ new Date(expiresAt).toUTCString() }}.
        </p>
        <form class="max-w-md space-y-4" novalidate @submit.prevent="submit">
            <div>
                <label for="name" class="block font-medium">Full name</label>
                <input
                    id="name" v-model="form.name" type="text" autocomplete="name" required
                    :aria-invalid="form.errors.name ? 'true' : undefined" aria-describedby="name-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                <p v-if="form.errors.name" id="name-error" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.name }}</p>
            </div>
            <div>
                <label for="password" class="block font-medium">Password</label>
                <input
                    id="password" v-model="form.password" type="password" autocomplete="new-password" required minlength="12"
                    :aria-invalid="form.errors.password ? 'true' : undefined" aria-describedby="password-help password-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                <p id="password-help" class="mt-1 text-sm text-slate-600">At least 12 characters.</p>
                <p v-if="form.errors.password" id="password-error" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.password }}</p>
            </div>
            <div>
                <label for="password_confirmation" class="block font-medium">Repeat password</label>
                <input
                    id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
            </div>
            <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">
                Create account
            </button>
        </form>
    </AppLayout>
</template>
