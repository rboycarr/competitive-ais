<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { decisionLabel, methodLabel, robotsLabel, typeLabel } from './labels';

type Source = {
    ulid: string; name: string; type: string; baseUrl: string | null; host: string | null; method: string; status: string; reliability: string;
    owner: string | null; proposedBy: string | null; proposedAt: string | null; classification: string; description: string | null;
    currentPolicyVersion: number | null;
};
type Policy = {
    version: number; decision: string; termsUrl: string | null; termsSummary: string | null; termsReviewedOn: string | null; robotsDecision: string;
    license: string | null; allowedCadence: string | null; rateBudgetPerHour: number | null; retentionDays: number | null;
    containsPersonalData: boolean; personalDataBasis: string | null; notes: string | null; decidedBy: string | null; decidedAt: string;
};

const props = defineProps<{
    source: Source; policies: Policy[]; canSubmit: boolean; canDecide: boolean; decidingOwnProposal: boolean; basedOnVersion: number;
    decisions: string[]; robotsDecisions: string[]; robotsBound: boolean;
}>();

// Linked only when it is an http(s) address; the system itself never opens it.
const safeLink = (url: string | null): string | null => (url !== null && /^https?:\/\//i.test(url) ? url : null);
const safeUrl = computed(() => safeLink(props.source.baseUrl));
const proposedAt = computed(() => (props.source.proposedAt === null ? '—' : new Date(props.source.proposedAt).toLocaleString()));

const submitForm = useForm({});
// The server reports a refused submission under `source`, which is not a field of this empty form.
const submitError = computed(() => (submitForm.errors as Record<string, string | undefined>).source);
const form = useForm({
    decision: '', based_on_version: props.basedOnVersion, terms_url: '', terms_summary: '', terms_reviewed_on: '', robots_decision: 'not_reviewed',
    license: '', allowed_cadence: '', rate_budget_per_hour: '', retention_days: '', contains_personal_data: false, personal_data_basis: '', notes: '',
});

function submitForReview(): void {
    submitForm.post(`/sources/${props.source.ulid}/submit`, { preserveScroll: true });
}

function decide(): void {
    form.post(`/sources/${props.source.ulid}/policies`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="props.source.name">
        <p class="mb-4 text-sm"><Link href="/sources" class="underline">All sources</Link></p>
        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Status</dt><dd>{{ props.source.status.replace('_', ' ') }}</dd>
            <dt class="font-medium">Policy in force</dt><dd>{{ props.source.currentPolicyVersion === null ? 'None' : `Version ${props.source.currentPolicyVersion}` }}</dd>
            <dt class="font-medium">Type</dt><dd>{{ typeLabel(props.source.type) }}</dd>
            <dt class="font-medium">Base URL</dt>
            <dd>
                <a v-if="safeUrl" :href="safeUrl" rel="noopener noreferrer nofollow" target="_blank" class="break-all underline">{{ safeUrl }}</a>
                <template v-else>—</template>
            </dd>
            <dt class="font-medium">Domain</dt><dd class="break-all">{{ props.source.host ?? '—' }}</dd>
            <dt class="font-medium">Collection method</dt><dd>{{ methodLabel(props.source.method) }}</dd>
            <dt class="font-medium">Default reliability</dt><dd>{{ props.source.reliability }}</dd>
            <dt class="font-medium">Owner</dt><dd>{{ props.source.owner ?? '—' }}</dd>
            <dt class="font-medium">Proposed by</dt><dd>{{ props.source.proposedBy ?? '—' }}</dd>
            <dt class="font-medium">Proposed</dt><dd>{{ proposedAt }}</dd>
            <dt class="font-medium">Classification</dt><dd>{{ props.source.classification }}</dd>
            <dt class="font-medium">Intended use</dt><dd class="whitespace-pre-line">{{ props.source.description ?? '—' }}</dd>
        </dl>

        <section v-if="props.canSubmit" aria-labelledby="submit-heading" class="mb-6 max-w-2xl">
            <h2 id="submit-heading" class="mb-1 text-lg font-semibold">Policy review</h2>
            <p class="mb-3 text-sm text-slate-700">When the proposal is complete, send it to a director, who reviews the source's terms and decides its policy.</p>
            <p v-if="submitError" class="mb-2 text-sm text-red-700" role="alert">{{ submitError }}</p>
            <button type="button" :disabled="submitForm.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50" @click="submitForReview">Submit for policy review</button>
        </section>

        <p v-if="props.decidingOwnProposal" class="mb-6 max-w-2xl text-sm text-slate-700">You proposed this source, so another director must decide its policy.</p>

        <section v-if="props.canDecide" aria-labelledby="decide-heading" class="mb-8 max-w-2xl">
            <h2 id="decide-heading" class="mb-1 text-lg font-semibold">Decide the policy</h2>
            <p class="mb-3 text-sm text-slate-700">Your decision is recorded as a new policy version and cannot be changed. Consult counsel where terms, licences or personal data need it.</p>
            <form class="space-y-3" novalidate @submit.prevent="decide">
                <fieldset>
                    <legend class="font-medium">Decision</legend>
                    <div class="mt-1 flex flex-wrap gap-4">
                        <label v-for="decision in props.decisions" :key="decision" class="flex items-center gap-2">
                            <input v-model="form.decision" type="radio" name="decision" :value="decision" required>
                            {{ decisionLabel(decision) }}
                        </label>
                    </div>
                </fieldset>
                <div>
                    <label for="policy-terms-url" class="block font-medium">Terms URL (optional)</label>
                    <input id="policy-terms-url" v-model="form.terms_url" type="url" inputmode="url" maxlength="2048" placeholder="https://" aria-describedby="policy-terms-url-help" :aria-invalid="form.errors.terms_url ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <p id="policy-terms-url-help" class="mt-1 text-xs text-slate-600">An http(s) address on a domain name, without a query string (?…) or fragment (#…).</p>
                </div>
                <div>
                    <label for="policy-terms-summary" class="block font-medium">Terms summary <span class="font-normal text-slate-600">(required to approve)</span></label>
                    <textarea id="policy-terms-summary" v-model="form.terms_summary" maxlength="10000" rows="3" :aria-invalid="form.errors.terms_summary ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="policy-reviewed-on" class="block font-medium">Terms reviewed on <span class="font-normal text-slate-600">(required to approve)</span></label>
                        <input id="policy-reviewed-on" v-model="form.terms_reviewed_on" type="date" :aria-invalid="form.errors.terms_reviewed_on ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="policy-robots" class="block font-medium">Robots decision</label>
                        <select id="policy-robots" v-model="form.robots_decision" aria-describedby="policy-robots-help" :aria-invalid="form.errors.robots_decision ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option v-for="robots in props.robotsDecisions" :key="robots" :value="robots">{{ robotsLabel(robots) }}</option>
                        </select>
                        <p v-if="props.robotsBound" id="policy-robots-help" class="mt-1 text-xs text-slate-600">This source would be read like a visitor reads pages, so it can only be approved where robots rules allow it.</p>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="policy-license" class="block font-medium">Licence (optional)</label>
                        <input id="policy-license" v-model="form.license" type="text" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="policy-cadence" class="block font-medium">Allowed cadence (optional)</label>
                        <input id="policy-cadence" v-model="form.allowed_cadence" type="text" maxlength="50" placeholder="e.g. daily" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="policy-rate" class="block font-medium">Requests per hour (optional)</label>
                        <input id="policy-rate" v-model="form.rate_budget_per_hour" type="number" min="1" max="1000000" step="1" :aria-invalid="form.errors.rate_budget_per_hour ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="policy-retention" class="block font-medium">Retention in days (optional)</label>
                        <input id="policy-retention" v-model="form.retention_days" type="number" min="1" max="36500" step="1" :aria-invalid="form.errors.retention_days ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 font-medium">
                        <input v-model="form.contains_personal_data" type="checkbox">
                        The source contains personal data
                    </label>
                </div>
                <div v-if="form.contains_personal_data">
                    <label for="policy-basis" class="block font-medium">Lawful basis for the personal data</label>
                    <textarea id="policy-basis" v-model="form.personal_data_basis" maxlength="10000" rows="2" required :aria-invalid="form.errors.personal_data_basis ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label for="policy-notes" class="block font-medium">Notes <span class="font-normal text-slate-600">(required to reject or ask for information)</span></label>
                    <textarea id="policy-notes" v-model="form.notes" maxlength="10000" rows="3" :aria-invalid="form.errors.notes ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" class="text-sm text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <button type="submit" :disabled="form.processing || form.decision === ''" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Record decision</button>
            </form>
        </section>

        <section aria-labelledby="policy-heading" class="max-w-2xl">
            <h2 id="policy-heading" class="mb-2 text-lg font-semibold">Policy history</h2>
            <p v-if="props.policies.length === 0" class="text-sm text-slate-700">No policy decision yet. Nobody may use this source for evidence until a director approves its policy.</p>
            <ol v-else class="space-y-4">
                <li v-for="policy in props.policies" :key="policy.version" class="rounded border border-slate-200 p-3 text-sm">
                    <h3 class="font-semibold">Version {{ policy.version }}: {{ decisionLabel(policy.decision) }}</h3>
                    <p class="mb-2 text-slate-700">{{ policy.decidedBy ?? 'Unknown' }}, {{ new Date(policy.decidedAt).toLocaleString() }}</p>
                    <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1">
                        <dt class="font-medium">Terms</dt>
                        <dd>
                            <a v-if="safeLink(policy.termsUrl)" :href="safeLink(policy.termsUrl) ?? undefined" rel="noopener noreferrer nofollow" target="_blank" class="break-all underline">{{ policy.termsUrl }}</a>
                            <template v-else>—</template>
                        </dd>
                        <dt class="font-medium">Terms summary</dt><dd class="whitespace-pre-line">{{ policy.termsSummary ?? '—' }}</dd>
                        <dt class="font-medium">Terms reviewed on</dt><dd>{{ policy.termsReviewedOn ?? '—' }}</dd>
                        <dt class="font-medium">Robots</dt><dd>{{ robotsLabel(policy.robotsDecision) }}</dd>
                        <dt class="font-medium">Licence</dt><dd>{{ policy.license ?? '—' }}</dd>
                        <dt class="font-medium">Allowed cadence</dt><dd>{{ policy.allowedCadence ?? '—' }}</dd>
                        <dt class="font-medium">Requests per hour</dt><dd>{{ policy.rateBudgetPerHour ?? '—' }}</dd>
                        <dt class="font-medium">Retention</dt><dd>{{ policy.retentionDays === null ? '—' : `${policy.retentionDays} days` }}</dd>
                        <dt class="font-medium">Personal data</dt><dd class="whitespace-pre-line">{{ policy.containsPersonalData ? `Yes. Lawful basis: ${policy.personalDataBasis ?? 'not recorded'}` : 'No' }}</dd>
                        <dt class="font-medium">Notes</dt><dd class="whitespace-pre-line">{{ policy.notes ?? '—' }}</dd>
                    </dl>
                </li>
            </ol>
        </section>
    </AppLayout>
</template>
