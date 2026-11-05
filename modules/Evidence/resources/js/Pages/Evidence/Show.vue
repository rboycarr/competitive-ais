<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { eventLabel, scanLabel, sizeLabel, statusLabel } from './labels';

type Asset = {
    ulid: string; title: string; description: string | null; status: string; scanStatus: string; originalFilename: string; sizeBytes: number;
    sourceUrl: string | null; capturedAt: string; uploadedBy: string | null; uploadedAt: string | null; classification: string;
};
type CustodyEvent = { event: string; actor: string | null; occurredAt: string };

const props = defineProps<{ asset: Asset; canDownload: boolean; custody: CustodyEvent[] }>();

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
        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Status</dt><dd>{{ statusLabel(props.asset.status) }}</dd>
            <dt class="font-medium">Scan</dt><dd>{{ scanLabel(props.asset.scanStatus) }}</dd>
            <dt class="font-medium">File</dt><dd class="break-all">{{ props.asset.originalFilename }} ({{ sizeLabel(props.asset.sizeBytes) }})</dd>
            <dt class="font-medium">Captured</dt><dd>{{ when(props.asset.capturedAt) }}</dd>
            <dt class="font-medium">Found at</dt>
            <dd>
                <a v-if="safeUrl" :href="safeUrl" rel="noopener noreferrer nofollow" target="_blank" class="break-all underline">{{ safeUrl }}</a>
                <template v-else>—</template>
            </dd>
            <dt class="font-medium">Uploaded by</dt><dd>{{ props.asset.uploadedBy ?? '—' }}</dd>
            <dt class="font-medium">Uploaded</dt><dd>{{ when(props.asset.uploadedAt) }}</dd>
            <dt class="font-medium">Classification</dt><dd>{{ props.asset.classification }}</dd>
            <dt class="font-medium">Description</dt><dd class="whitespace-pre-line">{{ props.asset.description ?? '—' }}</dd>
        </dl>
        <div v-if="props.canDownload" class="mb-6">
            <button type="button" class="rounded bg-slate-800 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="downloading" @click="download">
                Download file
            </button>
            <p class="mt-1 text-xs text-slate-600">Saved as a file, never opened in the browser. Each download is recorded in the chain of custody.</p>
            <p v-if="downloadError" class="mt-1 text-sm text-red-700" role="alert">{{ downloadError }}</p>
        </div>
        <section aria-labelledby="custody-heading" class="max-w-2xl">
            <h2 id="custody-heading" class="mb-2 text-lg font-semibold">Chain of custody</h2>
            <ol class="space-y-1 text-sm">
                <li v-for="(entry, index) in props.custody" :key="`${entry.occurredAt}-${index}`">
                    {{ when(entry.occurredAt) }}: {{ eventLabel(entry.event) }}<template v-if="entry.actor"> by {{ entry.actor }}</template>
                </li>
            </ol>
        </section>
    </AppLayout>
</template>
