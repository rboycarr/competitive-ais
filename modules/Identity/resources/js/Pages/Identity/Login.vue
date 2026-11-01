<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AppLayout title="Sign in">
        <form class="max-w-md space-y-4" novalidate @submit.prevent="submit">
            <p v-if="form.errors.email" id="login-error" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert">
                {{ form.errors.email }}
            </p>
            <div>
                <label for="email" class="block font-medium">Email</label>
                <input
                    id="email" v-model="form.email" type="email" autocomplete="username" required
                    :aria-invalid="form.errors.email ? 'true' : undefined" aria-describedby="login-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
            </div>
            <div>
                <label for="password" class="block font-medium">Password</label>
                <input
                    id="password" v-model="form.password" type="password" autocomplete="current-password" required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
            </div>
            <div class="flex items-center gap-2">
                <input id="remember" v-model="form.remember" type="checkbox" class="h-4 w-4">
                <label for="remember">Keep me signed in on this device</label>
            </div>
            <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">
                Sign in
            </button>
        </form>
    </AppLayout>
</template>
