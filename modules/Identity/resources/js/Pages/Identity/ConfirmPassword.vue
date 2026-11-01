<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const form = useForm({ password: '' });

function submit(): void {
    form.post('/user/confirm-password', { onFinish: () => form.reset() });
}
</script>

<template>
    <AppLayout title="Confirm your password">
        <p class="mb-4 text-slate-700">For your security, confirm your password to continue.</p>
        <form class="max-w-md space-y-4" novalidate @submit.prevent="submit">
            <div>
                <label for="password" class="block font-medium">Password</label>
                <input
                    id="password" v-model="form.password" type="password" autocomplete="current-password" required
                    :aria-invalid="form.errors.password ? 'true' : undefined" aria-describedby="password-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                <p v-if="form.errors.password" id="password-error" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.password }}</p>
            </div>
            <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">
                Confirm
            </button>
        </form>
    </AppLayout>
</template>
