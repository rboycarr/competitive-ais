<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { eventLabel, scanLabel, sizeLabel, statusLabel } from './labels';

type Asset = {
    ulid: string; title: string; description: string | null; status: string; scanStatus: string; originalFilename: string; sizeBytes: number;
    sourceUrl: string | null; capturedAt: string; uploadedBy: string | null; uploadedAt: string | null; classification: string; legalHold: boolean;
    competitors: string[]; markets: string[]; source: CaptureSource | null; sha256?: string | null;
    // P1-48: the stored bytes' integrity ('mismatch' after a failed re-hash) and when the asset was withdrawn.
    integrity?: string | null; withdrawnAt?: string | null;
};
// P1-45: the intelligence citing this evidence; items the viewer cannot see are only counted.
type Citations = { items: { ulid: string; title: string; status: string; relations: string[] }[]; hidden: number };
// P1-37: the source and the policy version in force at capture; a placeholder when the source is hidden from the viewer.
type CaptureSource = { visible: false } | { visible: true; ulid: string; name: string; status: string; policyVersion: number | null; policyDecidedAt: string | null };
type CustodyEvent = { event: string; actor: string | null; occurredAt: string; reason: string | null };

const props = defineProps<{ asset: Asset; canDownload: boolean; canHold: boolean; canWithdraw?: boolean; custody: CustodyEvent[]; citations?: Citations | null }>();

// P1-48: withdrawal is final and needs a reason; the server checks again and flags the intelligence citing it.
const withdrawForm = useForm<{ reason: string }>({ reason: '' });
function withdraw(): void {
    if (!window.confirm('Withdraw this evidence? It stays on record but no longer supports anything, and this cannot be undone.')) {
        return;
    }
    withdrawForm.post(`/evidence/${props.asset.ulid}/withdraw`, { preserveScroll: true, onSuccess: () => (withdrawForm.reason = '') });
}

// P1-35: a reason is required both to place and to release a hold (PO decision O35); the server checks again.
const holdForm = useForm<{ hold: boolean; reason: string }>({ hold: true, reason: '' });
function changeHold(): void {
    holdForm.hold = !props.asset.legalHold;
    holdForm.post(`/evidence/${props.asset.ulid}/hold`, { preserveScroll: true, onSuccess: () => (holdForm.reason = '') });
}

// The server checks again, records the download, and sends the browser to a link valid for a minute (P1-34).
const downloading = ref(false);
const downloadError = ref<string | null>(null);
function download(): void {
    downloadError.value = null;
    router.post(`/evidence/${props.asset.ulid}/download`, {}, {
        preserveScroll: true,
        onStart: () => (downloading.value = true),
        onFinish: () => (downloading.value = false),
        onError: (errors) => (downloadError.value = errors.download ?? 'The download could not be started.'),
    });
}

// Linked only when it is an http(s) address; the system itself never opens it.
const safeUrl = computed(() => (props.asset.sourceUrl !== null && /^https?:\/\//i.test(props.asset.sourceUrl) ? props.asset.sourceUrl : null));
const when = (iso: string | null): string => (iso === null ? '—' : new Date(iso).toLocaleString());
</script>

<template>
    <AppLayout :title="props.asset.title">
        <p class="mb-4 text-sm"><Link href="/evidence" class="underline">All evidence</Link></p>
        <p v-if="props.asset.status === 'quarantined'" class="mb-4 max-w-2xl rounded border border-amber-300 bg-amber-50 p-3 text-sm" role="status">
            In quarantine: the file has not been scanned yet, so nobody can open it, and only its uploader and directors see this record.
        </p>
        <p v-if="props.asset.status === 'withdrawn'" class="mb-4 max-w-2xl rounded border border-red-300 bg-red-50 p-3 text-sm" role="note">
            Withdrawn{{ props.asset.withdrawnAt ? ` on ${when(props.asset.withdrawnAt)}` : '' }}: kept on record for provenance, but it no longer supports
            any intelligence and cannot be downloaded. Intelligence citing it is flagged for re-review.
        </p>
        <p v-if="props.asset.integrity === 'mismatch'" class="mb-4 max-w-2xl rounded border border-red-300 bg-red-50 p-3 text-sm" role="note">
            Integrity check failed: the stored file no longer matches its SHA-256, or is missing. It cannot be downloaded or cited, and intelligence
            citing it is flagged for re-review.
        </p>
        <p v-if="props.asset.legalHold" class="mb-4 max-w-2xl rounded border border-red-300 bg-red-50 p-3 text-sm" role="note">
            On legal hold: the stored file cannot be deleted, whatever its retention date, until a director releases the hold.
        </p>
        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Status</dt><dd>{{ statusLabel(props.asset.status) }}</dd>
            <dt class="font-medium">Scan</dt><dd>{{ scanLabel(props.asset.scanStatus) }}</dd>
            <dt class="font-medium">File</dt><dd class="break-all">{{ props.asset.originalFilename }} ({{ sizeLabel(props.asset.sizeBytes) }})</dd>
            <dt class="font-medium">SHA-256</dt><dd class="break-all font-mono text-xs">{{ props.asset.sha256 ?? 'Not stored yet' }}</dd>
            <dt class="font-medium">Captured</dt><dd>{{ when(props.asset.capturedAt) }}</dd>
            <dt class="font-medium">Found at</dt>
            <dd>
                <a v-if="safeUrl" :href="safeUrl" rel="noopener noreferrer nofollow" target="_blank" class="break-all underline">{{ safeUrl }}</a>
                <template v-else>—</template>
            </dd>
            <dt class="font-medium">Source</dt>
            <dd>
                <template v-if="props.asset.source === null">Unregistered source</template>
                <template v-else-if="!props.asset.source.visible">Recorded, but not visible at your access</template>
                <template v-else>
                    <Link :href="`/sources/${props.asset.source.ulid}`" class="underline">{{ props.asset.source.name }}</Link>
                    — captured under policy version {{ props.asset.source.policyVersion }}, approved {{ when(props.asset.source.policyDecidedAt) }}
                    <span v-if="props.asset.source.status !== 'approved'" class="text-slate-600">(the source is now {{ props.asset.source.status.replace('_', ' ') }})</span>
                </template>
            </dd>
            <dt class="font-medium">Uploaded by</dt><dd>{{ props.asset.uploadedBy ?? '—' }}</dd>
            <dt class="font-medium">Uploaded</dt><dd>{{ when(props.asset.uploadedAt) }}</dd>
            <dt class="font-medium">Classification</dt><dd>{{ props.asset.classification }}</dd>
            <dt class="font-medium">Competitors</dt><dd>{{ props.asset.competitors.length > 0 ? props.asset.competitors.join(', ') : '—' }}</dd>
            <dt class="font-medium">Markets</dt><dd>{{ props.asset.markets.length > 0 ? props.asset.markets.join(', ') : '—' }}</dd>
            <dt class="font-medium">Description</dt><dd class="whitespace-pre-line">{{ props.asset.description ?? '—' }}</dd>
        </dl>
        <div v-if="props.canDownload" class="mb-6">
            <button type="button" class="rounded bg-slate-800 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="downloading" @click="download">
                Download file
            </button>
            <p class="mt-1 text-xs text-slate-600">Saved as a file, never opened in the browser. Each download is recorded in the chain of custody.</p>
            <p v-if="downloadError" class="mt-1 text-sm text-red-700" role="alert">{{ downloadError }}</p>
        </div>
        <form v-if="props.canHold" class="mb-6 max-w-2xl space-y-2" @submit.prevent="changeHold">
            <label for="hold-reason" class="block text-sm font-medium">
                {{ props.asset.legalHold ? 'Reason for releasing the legal hold' : 'Reason for placing a legal hold' }}
            </label>
            <textarea id="hold-reason" v-model="holdForm.reason" required maxlength="1000" rows="2" :aria-invalid="holdForm.errors.reason ? 'true' : undefined" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
            <button type="submit" class="rounded border border-slate-800 px-4 py-2 text-sm font-medium disabled:opacity-50" :disabled="holdForm.processing">
                {{ props.asset.legalHold ? 'Release legal hold' : 'Place on legal hold' }}
            </button>
            <ul v-if="Object.keys(holdForm.errors).length > 0" class="text-sm text-red-700" role="alert">
                <li v-for="(message, field) in holdForm.errors" :key="field">{{ message }}</li>
            </ul>
        </form>
        <form v-if="props.canWithdraw" class="mb-6 max-w-2xl space-y-2" @submit.prevent="withdraw">
            <label for="withdraw-reason" class="block text-sm font-medium">Reason for withdrawing this evidence</label>
            <textarea id="withdraw-reason" v-model="withdrawForm.reason" required maxlength="1000" rows="2" :aria-invalid="withdrawForm.errors.reason ? 'true' : undefined" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
            <button type="submit" class="rounded border border-red-700 px-4 py-2 text-sm font-medium text-red-800 disabled:opacity-50" :disabled="withdrawForm.processing">
                Withdraw evidence
            </button>
            <ul v-if="Object.keys(withdrawForm.errors).length > 0" class="text-sm text-red-700" role="alert">
                <li v-for="(message, field) in withdrawForm.errors" :key="field">{{ message }}</li>
            </ul>
        </form>
        <section v-if="props.citations" aria-labelledby="citations-heading" class="mb-6 max-w-2xl">
            <h2 id="citations-heading" class="mb-2 text-lg font-semibold">Cited by</h2>
            <p v-if="props.citations.items.length === 0 && props.citations.hidden === 0" class="text-sm text-slate-700">No intelligence cites it.</p>
            <ul class="space-y-1 text-sm">
                <li v-for="item in props.citations.items" :key="item.ulid">
                    <Link :href="`/intelligence/${item.ulid}`" class="underline">{{ item.title }}</Link>
                    <span class="text-slate-600"> ({{ item.status.replace('_', ' ') }}; {{ item.relations.join(', ') }})</span>
                </li>
                <li v-if="props.citations.hidden > 0" class="text-slate-700">{{ props.citations.hidden }} more {{ props.citations.hidden === 1 ? 'item' : 'items' }} hidden (insufficient access)</li>
            </ul>
        </section>
        <section aria-labelledby="custody-heading" class="max-w-2xl">
            <h2 id="custody-heading" class="mb-2 text-lg font-semibold">Chain of custody</h2>
            <ol class="space-y-1 text-sm">
                <li v-for="(entry, index) in props.custody" :key="`${entry.occurredAt}-${index}`">
                    {{ when(entry.occurredAt) }}: {{ eventLabel(entry.event) }}<template v-if="entry.actor"> by {{ entry.actor }}</template>
                    <span v-if="entry.reason" class="block whitespace-pre-line pl-4 text-slate-600">Reason: {{ entry.reason }}</span>
                </li>
            </ol>
        </section>
    </AppLayout>
</template>
