<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type SessionRow = { handle: string; ipAddress: string | null; userAgent: string | null; lastActiveAt: string; current: boolean };

const props = defineProps<{ twoFactorEnabled: boolean; twoFactorPending: boolean; sessions: SessionRow[] }>();
const otherSessions = computed(() => props.sessions.filter((session) => !session.current));

// P1-12: users with a privileged role are sent here until two-factor authentication is on.
const page = usePage<{ auth?: { mfaRequired?: boolean } }>();
const mfaRequired = computed(() => page.props.auth?.mfaRequired === true);

const qrImage = ref<string | null>(null);
const secretKey = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const confirm = useForm({ code: '' });

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return (await response.json()) as T;
}

// The QR code is an SVG from the server; it is shown as an image, never inserted as HTML (docs/07: no v-html).
async function loadSetup(): Promise<void> {
    const { svg } = await getJson<{ svg: string }>('/user/two-factor-qr-code');
    qrImage.value = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    secretKey.value = (await getJson<{ secretKey: string }>('/user/two-factor-secret-key')).secretKey;
}

async function loadRecoveryCodes(): Promise<void> {
    recoveryCodes.value = await getJson<string[]>('/user/two-factor-recovery-codes');
}

function enable(): void {
    router.post('/user/two-factor-authentication', {}, { preserveScroll: true, onSuccess: () => loadSetup() });
}

function confirmCode(): void {
    confirm.post('/user/confirmed-two-factor-authentication', { preserveScroll: true, onSuccess: () => loadRecoveryCodes(), onFinish: () => confirm.reset() });
}

function regenerate(): void {
    router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true, onSuccess: () => loadRecoveryCodes() });
}

function disable(): void {
    router.delete('/user/two-factor-authentication', { preserveScroll: true, onSuccess: () => { recoveryCodes.value = []; qrImage.value = null; } });
}

// P1-17: sign out other devices. The server identifies each session by a handle, never by its session ID.
function signOut(session: SessionRow): void {
    router.delete(`/user/sessions/${session.handle}`, { preserveScroll: true });
}

function signOutOthers(): void {
    if (window.confirm('Sign out all other devices?')) {
        router.delete('/user/sessions', { preserveScroll: true });
    }
}

function lastActive(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

if (props.twoFactorPending) {
    void loadSetup();
}
</script>

<template>
    <AppLayout title="Security">
        <section aria-labelledby="two-factor-heading" class="max-w-xl space-y-4">
            <h2 id="two-factor-heading" class="text-lg font-semibold">Two-factor authentication</h2>

            <p v-if="mfaRequired" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900" role="status">
                Your role needs two-factor authentication. Turn it on to continue; until then only this page and sign-out are available.
            </p>

            <template v-if="twoFactorEnabled">
                <p class="text-slate-700">Two-factor authentication is on. You will be asked for a code from your authenticator app when you sign in.</p>
                <div class="flex gap-3">
                    <button type="button" class="rounded border border-slate-300 px-4 py-2" @click="loadRecoveryCodes">Show recovery codes</button>
                    <button type="button" class="rounded border border-slate-300 px-4 py-2" @click="regenerate">New recovery codes</button>
                    <button type="button" class="rounded border border-red-300 px-4 py-2 text-red-800" @click="disable">Turn off</button>
                </div>
            </template>

            <template v-else-if="twoFactorPending || qrImage">
                <p class="text-slate-700">Scan this code with your authenticator app, then enter the 6-digit code it shows.</p>
                <img v-if="qrImage" :src="qrImage" alt="QR code for your authenticator app" class="h-48 w-48 border border-slate-200 bg-white p-2">
                <p v-if="secretKey" class="text-sm text-slate-600">Or enter this key manually: <code class="font-mono">{{ secretKey }}</code></p>
                <form class="space-y-3" novalidate @submit.prevent="confirmCode">
                    <label for="code" class="block font-medium">Code</label>
                    <input
                        id="code" v-model="confirm.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                        :aria-invalid="confirm.errors.code ? 'true' : undefined" aria-describedby="code-error"
                        class="w-40 rounded border border-slate-300 px-3 py-2"
                    >
                    <p v-if="confirm.errors.code" id="code-error" class="text-sm text-red-700" role="alert">{{ confirm.errors.code }}</p>
                    <button type="submit" :disabled="confirm.processing" class="block rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Confirm</button>
                </form>
            </template>

            <template v-else>
                <p class="text-slate-700">Protect your account with a code from an authenticator app in addition to your password.</p>
                <button type="button" class="rounded bg-slate-900 px-4 py-2 font-medium text-white" @click="enable">Turn on</button>
            </template>

            <div v-if="recoveryCodes.length > 0" class="rounded border border-amber-300 bg-amber-50 p-4">
                <p class="mb-2 font-medium">Recovery codes</p>
                <p class="mb-2 text-sm text-slate-700">Store these somewhere safe. Each one works once if you lose your authenticator app.</p>
                <ul class="grid grid-cols-2 gap-1 font-mono text-sm">
                    <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                </ul>
            </div>
        </section>

        <section aria-labelledby="sessions-heading" class="mt-10 max-w-xl space-y-4">
            <h2 id="sessions-heading" class="text-lg font-semibold">Where you're signed in</h2>
            <p class="text-sm text-slate-700">Signing out another device also ends "keep me signed in" everywhere; this device stays signed in.</p>
            <ul class="divide-y divide-slate-200 rounded border border-slate-200">
                <li v-for="session in props.sessions" :key="session.handle" class="flex items-start justify-between gap-4 p-3 text-sm">
                    <div>
                        <p class="font-medium">{{ session.userAgent ?? 'Unknown browser' }}</p>
                        <p class="text-slate-600">{{ session.ipAddress ?? 'Unknown address' }} · last active {{ lastActive(session.lastActiveAt) }}</p>
                    </div>
                    <span v-if="session.current" class="shrink-0 rounded bg-green-50 px-2 py-0.5 text-green-900">This device</span>
                    <button v-else type="button" class="shrink-0 underline" @click="signOut(session)">
                        Sign out<span class="sr-only"> {{ session.userAgent ?? 'this device' }}</span>
                    </button>
                </li>
            </ul>
            <button v-if="otherSessions.length > 0" type="button" class="rounded border border-red-300 px-4 py-2 text-red-800" @click="signOutOthers">
                Sign out all other devices
            </button>
        </section>
    </AppLayout>
</template>
