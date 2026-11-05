<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemForm from '../../Components/ItemForm.vue';
import { statusLabel, typeLabel } from './labels';

type Option = { ulid: string; name: string };
type Row = { ulid: string; title: string; type: string; status: string; classification: string; highImpact: boolean; competitors: string[]; updatedAt: string | null };

const props = defineProps<{
    items: Row[]; nextPage: string | null; filters: { status?: string; type?: string; classification?: string; competitor?: string };
    statuses: string[]; types: string[]; filterCompetitors: Option[];
    canCreate: boolean; classifications: string[]; competitorOptions: Option[]; productOptions: Option[]; marketOptions: Option[];
}>();

const filter = reactive({
    status: props.filters.status ?? '', type: props.filters.type ?? '', classification: props.filters.classification ?? '', competitor: props.filters.competitor ?? '',
});
const blank = {
    title: '', summary: '', item_type: '', classification: props.classifications.includes('internal') ? 'internal' : (props.classifications[0] ?? ''),
    high_impact: false, observed_at: null, published_at: null, collected_at: null, competitors: [], products: [], markets: [],
};

function applyFilters(): void {
    router.get('/intelligence', Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '')), { preserveScroll: true });
}

const updated = (iso: string | null): string => (iso === null ? '—' : new Date(iso).toLocaleString());
</script>

<template>
    <AppLayout title="Intelligence">
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label for="filter-status" class="block text-sm font-medium">Status</label>
                <select id="filter-status" v-model="filter.status" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="status in props.statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
                </select>
            </div>
            <div>
                <label for="filter-type" class="block text-sm font-medium">Type</label>
                <select id="filter-type" v-model="filter.type" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="type in props.types" :key="type" :value="type">{{ typeLabel(type) }}</option>
                </select>
            </div>
            <div>
                <label for="filter-classification" class="block text-sm font-medium">Classification</label>
                <select id="filter-classification" v-model="filter.classification" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="level in ['public', 'internal', 'confidential', 'restricted']" :key="level" :value="level">{{ level }}</option>
                </select>
            </div>
            <div>
                <label for="filter-competitor" class="block text-sm font-medium">Competitor</label>
                <select id="filter-competitor" v-model="filter.competitor" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="competitor in props.filterCompetitors" :key="competitor.ulid" :value="competitor.ulid">{{ competitor.name }}</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <p v-if="props.items.length === 0" class="mb-8 text-sm text-slate-700">No intelligence matches.</p>
        <div v-else class="mb-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Title</th>
                        <th scope="col" class="py-1 pr-3">Type</th>
                        <th scope="col" class="py-1 pr-3">Status</th>
                        <th scope="col" class="py-1 pr-3">Competitors</th>
                        <th scope="col" class="py-1 pr-3">Classification</th>
                        <th scope="col" class="py-1">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in props.items" :key="item.ulid" class="border-b border-slate-100">
                        <td class="py-1 pr-3">
                            <Link :href="`/intelligence/${item.ulid}`" class="underline">{{ item.title }}</Link>
                            <span v-if="item.highImpact" class="ml-2 rounded border border-amber-700 px-1 text-xs text-amber-800">High impact</span>
                        </td>
                        <td class="py-1 pr-3">{{ typeLabel(item.type) }}</td>
                        <td class="py-1 pr-3">{{ statusLabel(item.status) }}</td>
                        <td class="py-1 pr-3">{{ item.competitors.join(', ') || '—' }}</td>
                        <td class="py-1 pr-3">{{ item.classification }}</td>
                        <td class="py-1">{{ updated(item.updatedAt) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="props.nextPage" class="mt-2"><Link :href="props.nextPage" class="underline">Older items</Link></p>
        </div>

        <section v-if="props.canCreate" aria-labelledby="create-heading" class="max-w-3xl">
            <h2 id="create-heading" class="mb-1 text-lg font-semibold">Write an intelligence item</h2>
            <p class="mb-3 text-sm text-slate-700">It starts as a draft that only you, its reviewers and people who review intelligence can see. Add its claims on the next page.</p>
            <ItemForm
                id="new-item" :initial="blank" action="/intelligence" submit-label="Save draft"
                :types="props.types" :classifications="props.classifications"
                :competitor-options="props.competitorOptions" :product-options="props.productOptions" :market-options="props.marketOptions"
            />
        </section>
    </AppLayout>
</template>
