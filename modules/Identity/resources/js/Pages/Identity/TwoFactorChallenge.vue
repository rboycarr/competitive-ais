<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit(): void {
    form.transform((data) => (useRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .post('/two-factor-challenge', { onFinish: () => form.reset() });
}
</script>

<template>
    <AppLayout title="Two-factor authentication">
        <form class="max-w-md space-y-4" novalidate @submit.prevent="submit">
            <p v-if="form.errors.code || form.errors.recovery_code" id="challenge-error" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert">
                {{ form.errors.code || form.errors.recovery_code }}
            </p>
            <div v-if="!useRecovery">
                <label for="code" class="block font-medium">Code from your authenticator app</label>
                <input
                    id="code" v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                    aria-describedby="challenge-error" class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
            </div>
            <div v-else>
                <label for="recovery_code" class="block font-medium">Recovery code</label>
                <input
                    id="recovery_code" v-model="form.recovery_code" type="text" autocomplete="off" required
                    aria-describedby="challenge-error" class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
            </div>
            <div class="flex items-center gap-4">
                <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">
                    Continue
                </button>
                <button type="button" class="underline" @click="useRecovery = !useRecovery">
                    {{ useRecovery ? 'Use an authenticator code' : 'Use a recovery code' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
