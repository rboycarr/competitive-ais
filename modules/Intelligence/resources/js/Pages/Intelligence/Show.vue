<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MarkdownView, { type MarkdownNode } from '@/Components/MarkdownView';
import ItemForm, { type ItemFields } from '../../Components/ItemForm.vue';
import { claimTypeLabel, credibilityLabel, decisionChoiceLabel, decisionLabel, ratingTitle, relationLabel, reliabilityLabel, statusLabel, typeLabel } from './labels';

type Option = { ulid: string; name: string };
type Citation = {
    ulid: string; title: string; status: string; capturedAt: string; relation: string; locator: string | null; excerpt: string | null;
    source: { visible: true; name: string; reliability: string } | { visible: false } | null;
};
type Claim = {
    ulid: string; statement: string; type: string; reliability: string; credibility: number; status: string;
    evidence: Citation[]; hiddenEvidence: number;
};
type Review = {
    reviewer: string | null; submittedAt: string | null; verifiedAt: string | null;
    history: { ulid: string; decision: string; by: string | null; assignee: string | null; rationale: string | null; version: number; decidedAt: string }[];
    canAssign: boolean; reviewers: { ulid: string; name: string }[];
    canStart: boolean; canDecide: boolean; decisions: string[]; approvalProblems: string[];
};
type Linked = { ulid: string; title: string; status: string } | null;
type Publication = {
    releasedAt: string | null; supersedes: Linked; supersededBy: Linked;
    retraction: { reason: string; by: string | null; at: string; replacement: Linked } | null;
    canPublish: boolean; publishProblems: string[]; canRetract: boolean; replacementOptions: { ulid: string; title: string }[]; canReplace: boolean;
};

const props = defineProps<{
    item: {
        ulid: string; title: string; summary: MarkdownNode[]; type: string; classification: string; highImpact: boolean; status: string; version: number;
        author: string | null; owner: string | null; observedAt: string | null; publishedAt: string | null; collectedAt: string | null;
        createdAt: string | null; updatedAt: string | null;
    };
    subjects: { competitors: Option[]; products: Option[]; markets: (Option & { code: string })[]; hidden: number };
    claims: Claim[];
    canEdit: boolean;
    form: ItemFields | null;
    claimTypes: string[]; reliabilities: string[]; credibilities: number[];
    submission: { problems: string[] } | null;
    review: Review;
    publication: Publication;
    relations: string[]; evidenceOptions: { ulid: string; title: string; capturedAt: string }[]; evidenceSearch: string | null;
    types?: string[]; classifications?: string[]; competitorOptions?: Option[]; productOptions?: Option[]; marketOptions?: Option[];
}>();

const active = computed(() => props.claims.filter((c) => c.status !== 'withdrawn'));
const withdrawn = computed(() => props.claims.filter((c) => c.status === 'withdrawn'));
const base = computed(() => `/intelligence/${props.item.ulid}`);
const when = (iso: string | null): string => (iso === null ? 'Not given' : `${new Date(iso).toLocaleString()} (local time)`);

// One claim form, for adding or for editing the claim being edited. The ratings are never defaulted (ADR-0010).
const editing = ref<string | null>(null);
const claim = useForm({ statement: '', claim_type: '', source_reliability: '', info_credibility: '' as number | '' });
function startEdit(c: Claim): void {
    editing.value = c.ulid;
    Object.assign(claim, { statement: c.statement, claim_type: c.type, source_reliability: c.reliability, info_credibility: c.credibility });
    claim.clearErrors();
}
function cancelEdit(): void {
    editing.value = null;
    claim.reset();
    claim.clearErrors();
}
function saveClaim(): void {
    const options = { preserveScroll: true, onSuccess: () => cancelEdit() };
    const send = claim.transform((data) => ({ ...data, version: props.item.version }));
    if (editing.value === null) {
        send.post(`${base.value}/claims`, options);
    } else {
        send.put(`${base.value}/claims/${editing.value}`, options);
    }
}
function withdraw(c: Claim): void {
    router.post(`${base.value}/claims/${c.ulid}/withdraw`, { version: props.item.version }, { preserveScroll: true });
}
function move(c: Claim, direction: 'up' | 'down'): void {
    router.post(`${base.value}/claims/${c.ulid}/move`, { direction, version: props.item.version }, { preserveScroll: true });
}

// P1-41: citing evidence for one claim at a time. The relation is chosen, never defaulted; the search reloads only the options.
const citing = ref<string | null>(null);
const search = ref(props.evidenceSearch ?? '');
const cite = useForm({ evidence: '', relation: '', locator: '', excerpt: '' });
function startCite(c: Claim): void {
    citing.value = c.ulid;
    cite.reset();
    cite.clearErrors();
}
function cancelCite(): void {
    citing.value = null;
    cite.reset();
    cite.clearErrors();
}
function searchEvidence(): void {
    router.reload({ only: ['evidenceOptions', 'evidenceSearch'], data: { evidence_search: search.value } });
}
function saveCite(): void {
    cite.transform((data) => ({ ...data, version: props.item.version }))
        .post(`${base.value}/claims/${citing.value}/evidence`, { preserveScroll: true, onSuccess: () => cancelCite() });
}
// P1-42: submitting freezes the item until a reviewer decides; the server checks the guard again.
const submitting = ref(false);
const submitErrors = ref<string[]>([]);
function submit(): void {
    router.post(`${base.value}/submit`, { version: props.item.version }, {
        preserveScroll: true,
        onStart: () => { submitting.value = true; submitErrors.value = []; },
        onError: (errors) => { submitErrors.value = Object.values(errors).flat(); },
        onFinish: () => { submitting.value = false; },
    });
}
// P1-43: a director assigns the reviewer (a reason to reassign); the assigned reviewer starts the review, then decides.
// The decision is chosen, never defaulted; the server checks everything again.
const assignment = useForm({ reviewer: '', rationale: '' });
function assign(): void {
    assignment.post(`${base.value}/assign`, { preserveScroll: true, onSuccess: () => assignment.reset() });
}
const starting = ref(false);
function startReview(): void {
    router.post(`${base.value}/review`, {}, { preserveScroll: true, onStart: () => { starting.value = true; }, onFinish: () => { starting.value = false; } });
}
const verdict = useForm({ decision: '', rationale: '' });
const rationaleRequired = computed(() => verdict.decision === 'request_changes' || verdict.decision === 'reject');
function decide(): void {
    verdict.transform((data) => ({ ...data, version: props.item.version })).post(`${base.value}/decision`, { preserveScroll: true, onSuccess: () => verdict.reset() });
}
// P1-44: publishing, retracting (a reason; a replacement is optional) and drafting a replacement. The server checks again.
const publishing = ref(false);
const publishErrors = ref<string[]>([]);
function publish(): void {
    router.post(`${base.value}/publish`, { version: props.item.version }, {
        preserveScroll: true,
        onStart: () => { publishing.value = true; publishErrors.value = []; },
        onError: (errors) => { publishErrors.value = Object.values(errors).flat(); },
        onFinish: () => { publishing.value = false; },
    });
}
const retraction = useForm({ reason: '', replacement: '' });
function retract(): void {
    retraction.post(`${base.value}/retract`, { preserveScroll: true, onSuccess: () => retraction.reset() });
}
function draftReplacement(): void {
    router.post(`${base.value}/replacement`);
}
function uncite(c: Claim, e: Citation): void {
    router.post(`${base.value}/claims/${c.ulid}/evidence/remove`, { evidence: e.ulid, relation: e.relation, version: props.item.version }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="props.item.title">
        <p class="mb-3 flex flex-wrap gap-2 text-sm">
            <span class="rounded border border-slate-400 px-2">{{ statusLabel(props.item.status) }}</span>
            <span class="rounded border border-slate-400 px-2">{{ typeLabel(props.item.type) }}</span>
            <span class="rounded border border-slate-400 px-2">Classification: {{ props.item.classification }}</span>
            <span v-if="props.item.highImpact" class="rounded border border-amber-700 px-2 text-amber-800">High impact</span>
            <span class="rounded border border-slate-400 px-2">Version {{ props.item.version }}</span>
        </p>
        <p class="mb-4 text-sm text-slate-700">
            Author: {{ props.item.author ?? '—' }} · Owner: {{ props.item.owner ?? '—' }} ·
            <Link :href="`/intelligence/${props.item.ulid}/provenance`" class="underline">Provenance</Link>
        </p>

        <div v-if="props.publication.retraction" role="note" class="mb-4 max-w-3xl rounded border border-red-700 bg-red-50 p-3 text-sm">
            <p class="font-semibold text-red-800">Retracted {{ when(props.publication.retraction.at) }}<template v-if="props.publication.retraction.by"> by {{ props.publication.retraction.by }}</template>. Do not rely on it.</p>
            <p class="whitespace-pre-line">{{ props.publication.retraction.reason }}</p>
            <p v-if="props.publication.retraction.replacement">Replaced by <Link :href="`/intelligence/${props.publication.retraction.replacement.ulid}`" class="underline">{{ props.publication.retraction.replacement.title }}</Link></p>
        </div>
        <p v-if="props.publication.supersededBy" role="note" class="mb-4 max-w-3xl rounded border border-amber-700 bg-amber-50 p-3 text-sm">
            Superseded by <Link :href="`/intelligence/${props.publication.supersededBy.ulid}`" class="underline">{{ props.publication.supersededBy.title }}</Link>.
        </p>
        <p v-else-if="props.item.status === 'superseded'" role="note" class="mb-4 max-w-3xl rounded border border-amber-700 bg-amber-50 p-3 text-sm">Superseded by an item you cannot see.</p>
        <p v-if="props.publication.supersedes" class="mb-4 text-sm">
            {{ props.item.status === 'published' ? 'Replaces' : 'Will replace, once published,' }}
            <Link :href="`/intelligence/${props.publication.supersedes.ulid}`" class="underline">{{ props.publication.supersedes.title }}</Link>.
        </p>
        <p v-if="props.publication.releasedAt" class="mb-4 text-sm text-slate-700">Published here {{ when(props.publication.releasedAt) }}</p>

        <section aria-labelledby="summary-heading" class="mb-6 max-w-3xl">
            <h2 id="summary-heading" class="mb-1 text-lg font-semibold">Summary</h2>
            <MarkdownView v-if="props.item.summary.length > 0" :nodes="props.item.summary" />
            <p v-else class="text-sm text-slate-700">No summary yet.</p>
        </section>

        <section aria-labelledby="facts-heading" class="mb-6 grid max-w-3xl gap-4 sm:grid-cols-2">
            <h2 id="facts-heading" class="sr-only">Dates and subjects</h2>
            <dl class="text-sm">
                <dt class="font-medium">Observed</dt><dd class="mb-1">{{ when(props.item.observedAt) }}</dd>
                <dt class="font-medium">Published by the source</dt><dd class="mb-1">{{ when(props.item.publishedAt) }}</dd>
                <dt class="font-medium">Collected</dt><dd class="mb-1">{{ when(props.item.collectedAt) }}</dd>
            </dl>
            <dl class="text-sm">
                <dt class="font-medium">Competitors</dt><dd class="mb-1">{{ props.subjects.competitors.map((s) => s.name).join(', ') || '—' }}</dd>
                <dt class="font-medium">Products</dt><dd class="mb-1">{{ props.subjects.products.map((s) => s.name).join(', ') || '—' }}</dd>
                <dt class="font-medium">Markets</dt><dd class="mb-1">{{ props.subjects.markets.map((s) => `${s.code} · ${s.name}`).join(', ') || '—' }}</dd>
                <dd v-if="props.subjects.hidden > 0" class="text-slate-700">{{ props.subjects.hidden }} {{ props.subjects.hidden === 1 ? 'subject' : 'subjects' }} hidden (insufficient access)</dd>
            </dl>
        </section>

        <section aria-labelledby="claims-heading" class="mb-6 max-w-3xl">
            <h2 id="claims-heading" class="mb-1 text-lg font-semibold">Claims</h2>
            <p class="mb-2 text-sm text-slate-700">Each claim carries its kind and an Admiralty rating: source reliability (A–F) and information credibility (1–6), assigned by a person.</p>
            <p v-if="active.length === 0" class="text-sm text-slate-700">No claims yet.</p>
            <ol v-else class="space-y-2">
                <li v-for="(c, index) in active" :key="c.ulid" class="rounded border border-slate-200 p-2">
                    <p class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                        <span class="rounded bg-slate-900 px-2 font-mono text-white" :title="ratingTitle(c.reliability, c.credibility)">
                            {{ c.reliability }}{{ c.credibility }}<span class="sr-only"> — {{ ratingTitle(c.reliability, c.credibility) }}</span>
                        </span>
                        <span class="rounded border border-slate-400 px-2">{{ claimTypeLabel(c.type) }}</span>
                        <span v-if="c.status === 'disputed'" class="rounded border border-red-700 px-2 text-red-800">Disputed</span>
                    </p>
                    <p class="whitespace-pre-line">{{ c.statement }}</p>
                    <div class="mt-2 text-sm">
                        <h3 class="sr-only">Evidence for this claim</h3>
                        <p v-if="c.evidence.length === 0 && c.hiddenEvidence === 0" class="text-slate-700">No evidence cited yet.</p>
                        <ul v-else class="space-y-1">
                            <li v-for="e in c.evidence" :key="`${e.ulid}-${e.relation}`" class="border-l-2 border-slate-300 pl-2">
                                <span class="mr-1 rounded border border-slate-400 px-1">{{ relationLabel(e.relation) }}</span>
                                <Link :href="`/evidence/${e.ulid}`" class="underline">{{ e.title }}</Link>
                                <span v-if="e.status !== 'available'" class="ml-1 text-red-800">({{ e.status }})</span>
                                <span v-if="e.locator" class="ml-1 text-slate-700">· {{ e.locator }}</span>
                                <span class="block text-slate-700">
                                    <template v-if="e.source === null">Unregistered source</template>
                                    <template v-else-if="e.source.visible">Source: {{ e.source.name }} · default reliability {{ e.source.reliability }} ({{ reliabilityLabel(e.source.reliability) }})</template>
                                    <template v-else>Source hidden (insufficient access)</template>
                                </span>
                                <blockquote v-if="e.excerpt" class="mt-1 whitespace-pre-line border-l border-slate-200 pl-2 italic">{{ e.excerpt }}</blockquote>
                                <button v-if="props.canEdit" type="button" class="underline" @click="uncite(c, e)">Stop citing</button>
                            </li>
                        </ul>
                        <p v-if="c.hiddenEvidence > 0" class="text-slate-700">{{ c.hiddenEvidence }} more {{ c.hiddenEvidence === 1 ? 'citation' : 'citations' }} hidden (insufficient access)</p>
                    </div>
                    <form v-if="props.canEdit && citing === c.ulid" class="mt-2 space-y-2 rounded border border-slate-300 p-2 text-sm" novalidate @submit.prevent="saveCite">
                        <div class="flex flex-wrap items-end gap-2">
                            <div>
                                <label :for="`cite-search-${c.ulid}`" class="block font-medium">Find evidence by title</label>
                                <input :id="`cite-search-${c.ulid}`" v-model="search" type="search" maxlength="100" class="mt-1 rounded border border-slate-300 px-2 py-1" @keydown.enter.prevent="searchEvidence">
                            </div>
                            <button type="button" class="underline" @click="searchEvidence">Search</button>
                        </div>
                        <div>
                            <label :for="`cite-evidence-${c.ulid}`" class="block font-medium">Evidence</label>
                            <select :id="`cite-evidence-${c.ulid}`" v-model="cite.evidence" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                                <option value="" disabled>{{ props.evidenceOptions.length === 0 ? 'No available evidence found' : 'Choose' }}</option>
                                <option v-for="o in props.evidenceOptions" :key="o.ulid" :value="o.ulid">{{ o.title }} (captured {{ new Date(o.capturedAt).toLocaleDateString() }})</option>
                            </select>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div>
                                <label :for="`cite-relation-${c.ulid}`" class="block font-medium">The evidence…</label>
                                <select :id="`cite-relation-${c.ulid}`" v-model="cite.relation" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="" disabled>Choose</option>
                                    <option v-for="r in props.relations" :key="r" :value="r">{{ relationLabel(r) }}</option>
                                </select>
                            </div>
                            <div>
                                <label :for="`cite-locator-${c.ulid}`" class="block font-medium">Where in it (optional)</label>
                                <input :id="`cite-locator-${c.ulid}`" v-model="cite.locator" type="text" maxlength="255" placeholder="Page, section or timecode" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                            </div>
                        </div>
                        <div>
                            <label :for="`cite-excerpt-${c.ulid}`" class="block font-medium">Excerpt (optional)</label>
                            <textarea :id="`cite-excerpt-${c.ulid}`" v-model="cite.excerpt" maxlength="2000" rows="2" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" />
                        </div>
                        <ul v-if="Object.keys(cite.errors).length > 0" class="text-red-700" role="alert">
                            <li v-for="(message, field) in cite.errors" :key="field">{{ message }}</li>
                        </ul>
                        <div class="flex gap-3">
                            <button type="submit" :disabled="cite.processing" class="rounded bg-slate-900 px-3 py-1 font-medium text-white disabled:opacity-50">Cite</button>
                            <button type="button" class="underline" @click="cancelCite">Cancel</button>
                        </div>
                    </form>
                    <p v-if="props.canEdit" class="mt-2 flex flex-wrap gap-3 text-sm">
                        <button v-if="citing !== c.ulid" type="button" class="underline" @click="startCite(c)">Cite evidence</button>
                        <button type="button" class="underline" @click="startEdit(c)">Edit</button>
                        <button type="button" class="underline" :disabled="index === 0" @click="move(c, 'up')">Move up</button>
                        <button type="button" class="underline" :disabled="index === active.length - 1" @click="move(c, 'down')">Move down</button>
                        <button type="button" class="underline" @click="withdraw(c)">Withdraw</button>
                    </p>
                </li>
            </ol>
            <details v-if="withdrawn.length > 0" class="mt-2 text-sm">
                <summary>{{ withdrawn.length }} withdrawn {{ withdrawn.length === 1 ? 'claim' : 'claims' }} (kept on record)</summary>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="c in withdrawn" :key="c.ulid" class="whitespace-pre-line text-slate-700">{{ c.reliability }}{{ c.credibility }} · {{ claimTypeLabel(c.type) }}: {{ c.statement }}</li>
                </ul>
            </details>
        </section>

        <section v-if="props.submission" aria-labelledby="submit-heading" class="mb-6 max-w-3xl rounded border border-slate-300 p-3">
            <h2 id="submit-heading" class="mb-1 text-lg font-semibold">Submit for review</h2>
            <template v-if="props.submission.problems.length > 0">
                <p class="text-sm text-slate-700">Every claim needs at least one piece of available evidence that supports it. Still missing:</p>
                <ul class="list-disc pl-5 text-sm">
                    <li v-for="problem in props.submission.problems" :key="problem">{{ problem }}</li>
                </ul>
            </template>
            <template v-else>
                <p class="mb-2 text-sm text-slate-700">Every claim is evidence-backed. Once submitted, the item cannot be changed until a reviewer requests changes.</p>
                <button type="button" :disabled="submitting" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50" @click="submit">Submit for review</button>
            </template>
            <ul v-if="submitErrors.length > 0" class="mt-2 text-sm text-red-700" role="alert">
                <li v-for="message in submitErrors" :key="message">{{ message }}</li>
            </ul>
        </section>

        <section v-if="props.publication.canPublish || props.publication.canRetract || props.publication.canReplace" aria-labelledby="publication-heading" class="mb-6 max-w-3xl rounded border border-slate-300 p-3">
            <h2 id="publication-heading" class="mb-1 text-lg font-semibold">Publication</h2>
            <template v-if="props.publication.canPublish">
                <template v-if="props.publication.publishProblems.length > 0">
                    <p class="text-sm text-slate-700">It cannot be published yet:</p>
                    <ul class="list-disc pl-5 text-sm">
                        <li v-for="problem in props.publication.publishProblems" :key="problem">{{ problem }}</li>
                    </ul>
                </template>
                <template v-else>
                    <p class="mb-2 text-sm text-slate-700">Approved for this version. Publishing makes it readable by everyone with access to its subjects.</p>
                    <button type="button" :disabled="publishing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50" @click="publish">Publish</button>
                </template>
                <ul v-if="publishErrors.length > 0" class="mt-2 text-sm text-red-700" role="alert">
                    <li v-for="message in publishErrors" :key="message">{{ message }}</li>
                </ul>
            </template>

            <form v-if="props.publication.canRetract" class="mt-3 space-y-2" novalidate @submit.prevent="retract">
                <div>
                    <label for="retract-reason" class="block font-medium">Reason for retracting</label>
                    <textarea id="retract-reason" v-model="retraction.reason" required maxlength="5000" rows="2" :aria-invalid="retraction.errors.reason ? 'true' : undefined" aria-describedby="retract-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label for="retract-replacement" class="block font-medium">Replaced by (optional)</label>
                    <select id="retract-replacement" v-model="retraction.replacement" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        <option value="">None</option>
                        <option v-for="option in props.publication.replacementOptions" :key="option.ulid" :value="option.ulid">{{ option.title }}</option>
                    </select>
                </div>
                <ul id="retract-errors" class="text-sm text-red-700" role="alert">
                    <li v-for="message in Object.values(retraction.errors)" :key="message">{{ message }}</li>
                </ul>
                <button type="submit" :disabled="retraction.processing" class="rounded border border-red-700 px-4 py-2 font-medium text-red-800 disabled:opacity-50">Retract</button>
            </form>

            <div v-if="props.publication.canReplace" class="mt-3">
                <p class="mb-2 text-sm text-slate-700">A correction or update is a new item: it starts as a draft with this item's details, and supersedes this one when it is published.</p>
                <button type="button" class="rounded border border-slate-400 px-4 py-2 font-medium" @click="draftReplacement">Draft a replacement</button>
            </div>
        </section>

        <section v-if="props.review.history.length > 0 || props.review.reviewer !== null || props.review.canAssign || props.review.canStart || props.review.canDecide" aria-labelledby="review-heading" class="mb-6 max-w-3xl rounded border border-slate-300 p-3">
            <h2 id="review-heading" class="mb-1 text-lg font-semibold">Verification</h2>
            <p class="mb-2 text-sm text-slate-700">
                Reviewer: {{ props.review.reviewer ?? 'not assigned' }}
                <template v-if="props.review.submittedAt"> · Submitted {{ when(props.review.submittedAt) }}</template>
                <template v-if="props.review.verifiedAt"> · Approved {{ when(props.review.verifiedAt) }}</template>
            </p>

            <form v-if="props.review.canAssign" class="mb-3 space-y-2" novalidate @submit.prevent="assign">
                <div>
                    <label for="assign-reviewer" class="block font-medium">{{ props.review.reviewer === null ? 'Assign a reviewer' : 'Reassign the review' }}</label>
                    <select id="assign-reviewer" v-model="assignment.reviewer" required :aria-invalid="assignment.errors.reviewer ? 'true' : undefined" aria-describedby="assign-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        <option value="" disabled>Choose</option>
                        <option v-for="r in props.review.reviewers" :key="r.ulid" :value="r.ulid">{{ r.name }}</option>
                    </select>
                    <p v-if="props.review.reviewers.length === 0" class="mt-1 text-sm text-slate-700">Nobody else who reviews intelligence can see this item.</p>
                </div>
                <div v-if="props.review.reviewer !== null">
                    <label for="assign-rationale" class="block font-medium">Reason for reassigning</label>
                    <textarea id="assign-rationale" v-model="assignment.rationale" required maxlength="5000" rows="2" :aria-invalid="assignment.errors.rationale ? 'true' : undefined" aria-describedby="assign-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <ul id="assign-errors" class="text-sm text-red-700" role="alert">
                    <li v-for="message in Object.values(assignment.errors)" :key="message">{{ message }}</li>
                </ul>
                <button type="submit" :disabled="assignment.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">{{ props.review.reviewer === null ? 'Assign' : 'Reassign' }}</button>
            </form>

            <div v-if="props.review.canStart" class="mb-3">
                <p class="mb-2 text-sm text-slate-700">You are this item's reviewer. Start the review to record a decision.</p>
                <button type="button" :disabled="starting" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50" @click="startReview">Start review</button>
            </div>

            <form v-if="props.review.canDecide" class="mb-3 space-y-2" novalidate @submit.prevent="decide">
                <template v-if="props.review.approvalProblems.length > 0">
                    <p class="text-sm text-slate-700">You cannot approve it yet: every claim needs available supporting evidence that you can see.</p>
                    <ul class="list-disc pl-5 text-sm">
                        <li v-for="problem in props.review.approvalProblems" :key="problem">{{ problem }}</li>
                    </ul>
                </template>
                <fieldset>
                    <legend class="font-medium">Decision</legend>
                    <label v-for="d in props.review.decisions" :key="d" class="mr-4 inline-flex items-center gap-1">
                        <input v-model="verdict.decision" type="radio" name="decision" :value="d" :disabled="d === 'approve' && props.review.approvalProblems.length > 0">
                        {{ decisionChoiceLabel(d) }}
                    </label>
                </fieldset>
                <div>
                    <label for="decision-rationale" class="block font-medium">Rationale{{ rationaleRequired ? '' : ' (optional)' }}</label>
                    <textarea id="decision-rationale" v-model="verdict.rationale" :required="rationaleRequired" maxlength="5000" rows="3" :aria-invalid="verdict.errors.rationale ? 'true' : undefined" aria-describedby="decision-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <ul id="decision-errors" class="text-sm text-red-700" role="alert">
                    <li v-for="message in Object.values(verdict.errors)" :key="message">{{ message }}</li>
                </ul>
                <button type="submit" :disabled="verdict.processing || verdict.decision === ''" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Record decision</button>
            </form>

            <template v-if="props.review.history.length > 0">
                <h3 class="mt-2 font-medium">History</h3>
                <ol class="text-sm">
                    <li v-for="r in props.review.history" :key="r.ulid" class="border-b border-slate-100 py-1">
                        {{ decisionLabel(r.decision) }}<template v-if="r.assignee"> to {{ r.assignee }}</template>
                        by {{ r.by ?? '—' }} · version {{ r.version }} · {{ when(r.decidedAt) }}
                        <p v-if="r.rationale" class="whitespace-pre-line text-slate-700">{{ r.rationale }}</p>
                    </li>
                </ol>
            </template>
        </section>

        <section v-if="props.canEdit" aria-labelledby="claim-form-heading" class="mb-8 max-w-3xl">
            <h2 id="claim-form-heading" class="mb-2 text-lg font-semibold">{{ editing === null ? 'Add a claim' : 'Edit the claim' }}</h2>
            <form class="space-y-3" novalidate @submit.prevent="saveClaim">
                <div>
                    <label for="claim-statement" class="block font-medium">Statement</label>
                    <textarea id="claim-statement" v-model="claim.statement" required maxlength="2000" rows="3" :aria-invalid="claim.errors.statement ? 'true' : undefined" aria-describedby="claim-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="claim-type" class="block font-medium">Kind</label>
                        <select id="claim-type" v-model="claim.claim_type" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option value="" disabled>Choose</option>
                            <option v-for="type in props.claimTypes" :key="type" :value="type">{{ claimTypeLabel(type) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="claim-reliability" class="block font-medium">Source reliability</label>
                        <select id="claim-reliability" v-model="claim.source_reliability" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option value="" disabled>Choose</option>
                            <option v-for="grade in props.reliabilities" :key="grade" :value="grade">{{ grade }} — {{ reliabilityLabel(grade) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="claim-credibility" class="block font-medium">Information credibility</label>
                        <select id="claim-credibility" v-model="claim.info_credibility" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option value="" disabled>Choose</option>
                            <option v-for="grade in props.credibilities" :key="grade" :value="grade">{{ grade }} — {{ credibilityLabel(grade) }}</option>
                        </select>
                    </div>
                </div>
                <ul v-if="Object.keys(claim.errors).length > 0" id="claim-errors" class="text-sm text-red-700" role="alert">
                    <li v-for="(message, field) in claim.errors" :key="field">{{ message }}</li>
                </ul>
                <div class="flex gap-3">
                    <button type="submit" :disabled="claim.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">{{ editing === null ? 'Add claim' : 'Save claim' }}</button>
                    <button v-if="editing !== null" type="button" class="underline" @click="cancelEdit">Cancel</button>
                </div>
            </form>
        </section>

        <section v-if="props.canEdit && props.form" aria-labelledby="edit-heading" class="max-w-3xl">
            <details>
                <summary id="edit-heading" class="cursor-pointer text-lg font-semibold">Edit the item</summary>
                <div class="mt-3">
                    <ItemForm
                        id="edit-item" :initial="props.form" :version="props.item.version" :action="base" submit-label="Save item"
                        :types="props.types ?? []" :classifications="props.classifications ?? []"
                        :competitor-options="props.competitorOptions ?? []" :product-options="props.productOptions ?? []" :market-options="props.marketOptions ?? []"
                    />
                </div>
            </details>
        </section>
    </AppLayout>
</template>
