<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { methodLabel, typeLabel } from './labels';

type Source = {
    ulid: string; name: string; type: string; baseUrl: string | null; host: string | null; method: string; status: string; reliability: string;
    owner: string | null; proposedBy: string | null; proposedAt: string | null; classification: string; description: string | null;
};

const props = defineProps<{ source: Source }>();

// Linked only when it is an http(s) address; the system itself never opens it.
const safeUrl = computed(() => (props.source.baseUrl !== null && /^https?:\/\//i.test(props.source.baseUrl) ? props.source.baseUrl : null));
const proposedAt = computed(() => (props.source.proposedAt === null ? '—' : new Date(props.source.proposedAt).toLocaleString()));
</script>

<template>
    <AppLayout :title="props.source.name">
        <p class="mb-4 text-sm"><Link href="/sources" class="underline">All sources</Link></p>
        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Status</dt><dd>{{ props.source.status.replace('_', ' ') }}</dd>
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
        <section aria-labelledby="policy-heading" class="max-w-2xl">
            <h2 id="policy-heading" class="mb-2 text-lg font-semibold">Policy history</h2>
            <p class="text-sm text-slate-700">No policy decision yet. Nobody may use this source for evidence until a director approves its policy.</p>
        </section>
    </AppLayout>
</template>
