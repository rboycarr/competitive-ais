<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CompetitorForm, { type CompetitorFields } from './CompetitorForm.vue';

type Option = { ulid: string; name: string };
type Competitor = {
    ulid: string; name: string; legalName: string | null; website: string | null; hqCountry: string | null; lifecycle: string;
    tier: string | null; analyst: Option | null; classification: string; description: string | null;
};

const props = defineProps<{
    competitor: Competitor; lifecycles: string[]; canManage: boolean; tiers: string[]; analysts: Option[]; classifications: string[];
}>();

// The server accepts only http(s) addresses; checked again here so no other scheme becomes a link.
const safeWebsite = computed(() => (props.competitor.website !== null && /^https?:\/\//i.test(props.competitor.website) ? props.competitor.website : null));
const editing = ref(false);
const lifecycle = ref(props.competitor.lifecycle);
const current = computed<CompetitorFields>(() => ({
    name: props.competitor.name, legal_name: props.competitor.legalName ?? '', website: props.competitor.website ?? '',
    hq_country: props.competitor.hqCountry ?? '', tier: props.competitor.tier ?? '', analyst: props.competitor.analyst?.ulid ?? '',
    classification: props.competitor.classification, description: props.competitor.description ?? '',
}));

function setLifecycle(): void {
    if (lifecycle.value === 'archived' && !window.confirm(`Archive ${props.competitor.name}? It leaves the working list but stays on record.`)) {
        lifecycle.value = props.competitor.lifecycle;
        return;
    }
    router.put(`/competitors/${props.competitor.ulid}/lifecycle`, { lifecycle: lifecycle.value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="props.competitor.name">
        <p class="mb-4 text-sm"><Link href="/competitors" class="underline">All competitors</Link></p>

        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Legal name</dt><dd>{{ props.competitor.legalName ?? '—' }}</dd>
            <dt class="font-medium">Website</dt>
            <dd>
                <a v-if="safeWebsite" :href="safeWebsite" rel="noopener noreferrer nofollow" target="_blank" class="underline">{{ safeWebsite }}</a>
                <template v-else>—</template>
            </dd>
            <dt class="font-medium">Headquarters</dt><dd>{{ props.competitor.hqCountry ?? '—' }}</dd>
            <dt class="font-medium">Lifecycle</dt><dd>{{ props.competitor.lifecycle }}</dd>
            <dt class="font-medium">Tier</dt><dd>{{ props.competitor.tier ?? '—' }}</dd>
            <dt class="font-medium">Assigned analyst</dt><dd>{{ props.competitor.analyst?.name ?? '—' }}</dd>
            <dt class="font-medium">Classification</dt><dd>{{ props.competitor.classification }}</dd>
            <dt class="font-medium">Description</dt><dd class="whitespace-pre-line">{{ props.competitor.description ?? '—' }}</dd>
        </dl>

        <template v-if="props.canManage">
            <section aria-labelledby="lifecycle-heading" class="mb-6 max-w-2xl">
                <h2 id="lifecycle-heading" class="mb-2 text-lg font-semibold">Lifecycle</h2>
                <form class="flex items-end gap-2" @submit.prevent="setLifecycle">
                    <div>
                        <label for="lifecycle" class="block text-sm font-medium">Status</label>
                        <select id="lifecycle" v-model="lifecycle" class="mt-1 rounded border border-slate-300 px-2 py-1">
                            <option v-for="option in props.lifecycles" :key="option" :value="option">{{ option }}</option>
                        </select>
                    </div>
                    <button type="submit" class="rounded border border-slate-300 px-3 py-1">Set</button>
                </form>
            </section>

            <section aria-labelledby="edit-heading" class="max-w-2xl">
                <h2 id="edit-heading" class="mb-2 text-lg font-semibold">Profile</h2>
                <button v-if="!editing" type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = true">Edit profile</button>
                <CompetitorForm v-else :initial="current" method="put" :url="`/competitors/${props.competitor.ulid}`" id-prefix="edit" :tiers="props.tiers" :analysts="props.analysts" :classifications="props.classifications" submit-label="Save changes" @saved="editing = false" />
            </section>
        </template>
    </AppLayout>
</template>
