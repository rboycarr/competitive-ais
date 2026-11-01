<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Option = { ulid: string; name: string };
type GrantRow = {
    ulid: string; granteeType: 'user' | 'team'; grantee: string | null; scope: string; maxClassification: string;
    canExport: boolean; reason: string; grantedBy: string | null; grantedAt: string | null; expiresAt: string | null;
    revokedBy: string | null; revokedAt: string | null; status: 'active' | 'expired' | 'revoked';
};

const props = defineProps<{ grants: GrantRow[]; users: Option[]; teams: Option[]; classifications: string[]; canGrantExport: boolean }>();

const form = useForm({ grantee_type: 'user', grantee: '', max_classification: '', can_export: false, reason: '', expires_at: '' });
const grantees = computed(() => (form.grantee_type === 'user' ? props.users : props.teams));
const firstError = computed(() => Object.values(form.errors)[0] ?? null);
const page = usePage<{ errors?: Record<string, string> }>();
const revokeError = computed(() => page.props.errors?.grant ?? null);

function when(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'long' }); // long includes the time zone
}

function submit(): void {
    // The field holds local time; the server stores UTC.
    form.transform((data) => ({ ...data, expires_at: data.expires_at === '' ? null : new Date(data.expires_at).toISOString() }))
        .post('/admin/access-grants', { preserveScroll: true, onSuccess: () => form.reset() });
}

function revoke(grant: GrantRow): void {
    if (window.confirm(`Revoke the access grant for ${grant.grantee ?? 'this grantee'}?`)) {
        router.post(`/admin/access-grants/${grant.ulid}/revoke`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Access grants">
        <section aria-labelledby="grants-heading" class="mb-8">
            <h2 id="grants-heading" class="mb-2 text-lg font-semibold">Grants</h2>
            <p v-if="revokeError" class="mb-2 text-sm text-red-700" role="alert">{{ revokeError }}</p>
            <p v-if="props.grants.length === 0" class="text-sm text-slate-700">No access grants yet.</p>
            <table v-else class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Grantee</th>
                        <th scope="col" class="py-1 pr-3">Scope</th>
                        <th scope="col" class="py-1 pr-3">Up to</th>
                        <th scope="col" class="py-1 pr-3">Reason</th>
                        <th scope="col" class="py-1 pr-3">Granted</th>
                        <th scope="col" class="py-1 pr-3">Expires</th>
                        <th scope="col" class="py-1 pr-3">Status</th>
                        <th scope="col" class="py-1"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="grant in props.grants" :key="grant.ulid" class="border-b border-slate-100 align-top">
                        <td class="py-1 pr-3">{{ grant.grantee }} <span class="text-slate-600">({{ grant.granteeType }})</span></td>
                        <td class="py-1 pr-3">{{ grant.scope }}<span v-if="grant.canExport">, export</span></td>
                        <td class="py-1 pr-3">{{ grant.maxClassification }}</td>
                        <td class="py-1 pr-3">{{ grant.reason }}</td>
                        <td class="py-1 pr-3">{{ when(grant.grantedAt) }} by {{ grant.grantedBy ?? '—' }}</td>
                        <td class="py-1 pr-3">{{ grant.expiresAt === null ? 'Never' : when(grant.expiresAt) }}</td>
                        <td class="py-1 pr-3">
                            {{ grant.status }}
                            <span v-if="grant.status === 'revoked'" class="block text-slate-600">{{ when(grant.revokedAt) }} by {{ grant.revokedBy ?? '—' }}</span>
                        </td>
                        <td class="py-1">
                            <button v-if="grant.status !== 'revoked'" type="button" class="underline" @click="revoke(grant)">
                                Revoke<span class="sr-only"> the grant for {{ grant.grantee }}</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section aria-labelledby="new-grant-heading" class="max-w-xl">
            <h2 id="new-grant-heading" class="mb-2 text-lg font-semibold">New grant</h2>
            <p class="mb-3 text-sm text-slate-700">Opens all records up to the chosen classification, within each person's own clearance. Grants for single competitors or markets arrive with the registry.</p>
            <form class="space-y-3" novalidate @submit.prevent="submit">
                <fieldset>
                    <legend class="font-medium">Grant to</legend>
                    <label class="mr-4"><input v-model="form.grantee_type" type="radio" value="user" @change="form.grantee = ''"> A user</label>
                    <label><input v-model="form.grantee_type" type="radio" value="team" @change="form.grantee = ''"> A team</label>
                </fieldset>
                <div>
                    <label for="grant-grantee" class="block font-medium">{{ form.grantee_type === 'user' ? 'User' : 'Team' }}</label>
                    <select id="grant-grantee" v-model="form.grantee" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2" aria-describedby="grant-error">
                        <option value="" disabled>Choose…</option>
                        <option v-for="option in grantees" :key="option.ulid" :value="option.ulid">{{ option.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="grant-classification" class="block font-medium">Up to classification</label>
                    <select id="grant-classification" v-model="form.max_classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2" aria-describedby="grant-error">
                        <option value="" disabled>Choose…</option>
                        <option v-for="classification in props.classifications" :key="classification" :value="classification">{{ classification }}</option>
                    </select>
                </div>
                <div v-if="props.canGrantExport">
                    <label><input v-model="form.can_export" type="checkbox"> Allow exports</label>
                </div>
                <div>
                    <label for="grant-reason" class="block font-medium">Reason</label>
                    <textarea id="grant-reason" v-model="form.reason" required minlength="3" maxlength="500" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" aria-describedby="grant-error" />
                </div>
                <div>
                    <label for="grant-expires" class="block font-medium">Expires (your local time; leave empty for no expiry)</label>
                    <input id="grant-expires" v-model="form.expires_at" type="datetime-local" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" aria-describedby="grant-error">
                </div>
                <p v-if="firstError" id="grant-error" class="text-sm text-red-700" role="alert">{{ firstError }}</p>
                <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Grant access</button>
            </form>
        </section>
    </AppLayout>
</template>
