<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { claimTypeLabel, decisionLabel, ratingTitle, relationLabel, statusLabel } from './labels';

type Linked = { ulid: string; title: string; status: string } | null;
type Source = { visible: false } | { visible: true; ulid: string; name: string; policyVersion: number | null; policyDecidedAt: string | null } | null;
type Citation = {
    relation: string; locator: string | null; excerpt: string | null;
    asset: {
        ulid: string; title: string; status: string; classification: string; capturedAt: string; uploadedBy: string | null;
        sha256: string | null; sizeBytes: number | null; mime: string | null; integrity: string | null; source: Source;
        custody: { event: string; actor: string | null; occurredAt: string }[];
    };
};
type Claim = { ulid: string; statement: string; type: string; reliability: string; credibility: number; status: string; evidence: Citation[]; hiddenEvidence: number };

const props = defineProps<{
    item: {
        ulid: string; title: string; status: string; classification: string; version: number; author: string | null; owner: string | null;
        submittedAt: string | null; verifiedAt: string | null; releasedAt: string | null;
    };
    content: { hash: string; approvedHash: string | null; matchesApproval: boolean | null };
    claims: Claim[];
    reviews: { ulid: string; decision: string; by: string | null; assignee: string | null; rationale: string | null; version: number; contentHash: string; decidedAt: string }[];
    retraction: { reason: string; by: string | null; at: string; replacement: Linked } | null;
    supersedes: Linked; supersededBy: Linked;
    audit: { events: { ulid: string; action: string; actor: string; occurredAt: string; context: Record<string, unknown> }[]; more: boolean } | null;
}>();

const when = (iso: string | null): string => (iso === null ? '—' : `${new Date(iso).toLocaleString()} (local time)`);
const short = (hash: string): string => `${hash.slice(0, 12)}…`;
</script>

<template>
    <AppLayout :title="`Provenance: ${props.item.title}`">
        <p class="mb-3 text-sm"><Link :href="`/intelligence/${props.item.ulid}`" class="underline">Back to the item</Link></p>
        <h1 class="mb-1 text-xl font-semibold">Provenance: {{ props.item.title }}</h1>
        <p class="mb-4 text-sm text-slate-700">
            {{ statusLabel(props.item.status) }} · version {{ props.item.version }} · classification {{ props.item.classification }} ·
            author {{ props.item.author ?? '—' }} · owner {{ props.item.owner ?? '—' }}
        </p>
        <dl class="mb-6 grid max-w-3xl grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-sm">
            <dt class="font-medium">Submitted</dt><dd>{{ when(props.item.submittedAt) }}</dd>
            <dt class="font-medium">Approved</dt><dd>{{ when(props.item.verifiedAt) }}</dd>
            <dt class="font-medium">Published here</dt><dd>{{ when(props.item.releasedAt) }}</dd>
            <dt class="font-medium">Content SHA-256</dt><dd class="break-all font-mono text-xs">{{ props.content.hash }}</dd>
            <dt class="font-medium">Matches its approval</dt>
            <dd>
                <template v-if="props.content.matchesApproval === null">No approval of this version</template>
                <template v-else-if="props.content.matchesApproval">Yes: the content is what the reviewer approved</template>
                <strong v-else class="text-red-800">No: the content changed after it was approved</strong>
            </dd>
        </dl>

        <div v-if="props.retraction" role="note" class="mb-4 max-w-3xl rounded border border-red-700 bg-red-50 p-3 text-sm">
            <p class="font-semibold text-red-800">Retracted {{ when(props.retraction.at) }}<template v-if="props.retraction.by"> by {{ props.retraction.by }}</template></p>
            <p class="whitespace-pre-line">{{ props.retraction.reason }}</p>
            <p v-if="props.retraction.replacement">Replaced by <Link :href="`/intelligence/${props.retraction.replacement.ulid}`" class="underline">{{ props.retraction.replacement.title }}</Link></p>
        </div>
        <p v-if="props.supersedes" class="mb-2 text-sm">Replaces <Link :href="`/intelligence/${props.supersedes.ulid}/provenance`" class="underline">{{ props.supersedes.title }}</Link></p>
        <p v-if="props.supersededBy" class="mb-4 text-sm">Superseded by <Link :href="`/intelligence/${props.supersededBy.ulid}/provenance`" class="underline">{{ props.supersededBy.title }}</Link></p>

        <section aria-labelledby="claims-heading" class="mb-8 max-w-4xl">
            <h2 id="claims-heading" class="mb-2 text-lg font-semibold">Claims and their evidence</h2>
            <ol class="space-y-4">
                <li v-for="(claim, index) in props.claims" :key="claim.ulid" class="rounded border border-slate-200 p-3">
                    <p class="text-sm">
                        <span class="font-medium">Claim {{ index + 1 }}</span>
                        <abbr :title="ratingTitle(claim.reliability, claim.credibility)" class="ml-2 rounded border border-slate-400 px-1 no-underline">{{ claim.reliability }}{{ claim.credibility }}</abbr>
                        · {{ claimTypeLabel(claim.type) }}<template v-if="claim.status !== 'active'"> · {{ claim.status }}</template>
                    </p>
                    <p class="mb-2 whitespace-pre-line">{{ claim.statement }}</p>
                    <ul class="space-y-3 pl-4">
                        <li v-for="(citation, n) in claim.evidence" :key="`${citation.asset.ulid}-${citation.relation}-${n}`" class="border-l-2 border-slate-300 pl-3 text-sm">
                            <p>
                                <span class="font-medium">{{ relationLabel(citation.relation) }}:</span>
                                <Link :href="`/evidence/${citation.asset.ulid}`" class="underline">{{ citation.asset.title }}</Link>
                                ({{ citation.asset.status }}, {{ citation.asset.classification }})<template v-if="citation.locator"> · {{ citation.locator }}</template>
                            </p>
                            <p v-if="citation.excerpt" class="whitespace-pre-line text-slate-700">“{{ citation.excerpt }}”</p>
                            <dl class="mt-1 grid grid-cols-[max-content_1fr] gap-x-4 text-xs">
                                <dt>SHA-256</dt><dd class="break-all font-mono">{{ citation.asset.sha256 ?? 'No stored content' }}</dd>
                                <dt>Content</dt><dd>{{ citation.asset.mime ?? '—' }}<template v-if="citation.asset.sizeBytes !== null">, {{ citation.asset.sizeBytes }} bytes</template><template v-if="citation.asset.integrity">, integrity {{ citation.asset.integrity }}</template></dd>
                                <dt>Captured</dt><dd>{{ when(citation.asset.capturedAt) }} · uploaded by {{ citation.asset.uploadedBy ?? '—' }}</dd>
                                <dt>Source</dt>
                                <dd>
                                    <template v-if="citation.asset.source === null">Unregistered source</template>
                                    <template v-else-if="!citation.asset.source.visible">Recorded, but not visible at your access</template>
                                    <template v-else>
                                        <Link :href="`/sources/${citation.asset.source.ulid}`" class="underline">{{ citation.asset.source.name }}</Link>,
                                        policy version {{ citation.asset.source.policyVersion }} (approved {{ when(citation.asset.source.policyDecidedAt) }})
                                    </template>
                                </dd>
                            </dl>
                            <details class="mt-1 text-xs">
                                <summary>Chain of custody ({{ citation.asset.custody.length }})</summary>
                                <ol class="pl-4">
                                    <li v-for="(entry, k) in citation.asset.custody" :key="`${entry.occurredAt}-${k}`">{{ when(entry.occurredAt) }}: {{ entry.event.replace('_', ' ') }}<template v-if="entry.actor"> by {{ entry.actor }}</template></li>
                                </ol>
                            </details>
                        </li>
                        <li v-if="claim.hiddenEvidence > 0" class="text-sm text-slate-700">{{ claim.hiddenEvidence }} evidence {{ claim.hiddenEvidence === 1 ? 'item' : 'items' }} hidden (insufficient access)</li>
                        <li v-if="claim.evidence.length === 0 && claim.hiddenEvidence === 0" class="text-sm text-slate-700">No evidence cited.</li>
                    </ul>
                </li>
            </ol>
        </section>

        <section aria-labelledby="reviews-heading" class="mb-8 max-w-4xl">
            <h2 id="reviews-heading" class="mb-2 text-lg font-semibold">Reviews</h2>
            <p v-if="props.reviews.length === 0" class="text-sm text-slate-700">No reviews yet.</p>
            <ol class="space-y-1 text-sm">
                <li v-for="r in props.reviews" :key="r.ulid">
                    {{ when(r.decidedAt) }}: {{ decisionLabel(r.decision) }}<template v-if="r.assignee"> to {{ r.assignee }}</template> by {{ r.by ?? '—' }},
                    version {{ r.version }}, content <span class="font-mono" :title="r.contentHash">{{ short(r.contentHash) }}</span>
                    <span v-if="r.contentHash === props.content.hash" class="text-slate-600"> (as now)</span>
                    <p v-if="r.rationale" class="whitespace-pre-line pl-4 text-slate-700">{{ r.rationale }}</p>
                </li>
            </ol>
        </section>

        <section v-if="props.audit" aria-labelledby="audit-heading" class="max-w-4xl">
            <h2 id="audit-heading" class="mb-2 text-lg font-semibold">Audit trail</h2>
            <ol class="space-y-1 text-sm">
                <li v-for="e in props.audit.events" :key="e.ulid">
                    {{ when(e.occurredAt) }}: <span class="font-mono">{{ e.action }}</span> by {{ e.actor }}
                    <span class="font-mono text-xs text-slate-600">{{ JSON.stringify(e.context) }}</span>
                </li>
            </ol>
            <p v-if="props.audit.more" class="mt-2 text-sm">More events: <Link :href="`/admin/audit?subject=${props.item.ulid}`" class="underline">search the audit log</Link>.</p>
        </section>
    </AppLayout>
</template>
