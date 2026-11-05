<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MarkdownView, { type MarkdownNode } from '@/Components/MarkdownView';
import ItemForm, { type ItemFields } from '../../Components/ItemForm.vue';
import { claimTypeLabel, credibilityLabel, ratingTitle, reliabilityLabel, statusLabel, typeLabel } from './labels';

type Option = { ulid: string; name: string };
type Claim = { ulid: string; statement: string; type: string; reliability: string; credibility: number; status: string };

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
        <p class="mb-4 text-sm text-slate-700">Author: {{ props.item.author ?? '—' }} · Owner: {{ props.item.owner ?? '—' }}</p>

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
                    <p v-if="props.canEdit" class="mt-2 flex flex-wrap gap-3 text-sm">
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
