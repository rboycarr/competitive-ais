<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { eventLabel, scanLabel, sizeLabel, statusLabel } from './labels';

type Asset = {
    ulid: string; title: string; description: string | null; status: string; scanStatus: string; originalFilename: string; sizeBytes: number;
    sourceUrl: string | null; capturedAt: string; uploadedBy: string | null; uploadedAt: string | null; classification: string;
};
type CustodyEvent = { event: string; actor: string | null; occurredAt: string };

const props = defineProps<{ asset: Asset; custody: CustodyEvent[] }>();

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
