<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { methodLabel, typeLabel } from './labels';

type Option = { ulid: string; name: string };
type Row = { ulid: string; name: string; type: string; host: string | null; method: string; status: string; reliability: string; owner: string | null; classification: string };

const props = defineProps<{
    sources: Row[]; filters: { status?: string; type?: string }; statuses: string[]; types: string[];
    canPropose: boolean; methods: string[]; reliabilities: string[]; owners: Option[]; classifications: string[];
}>();

const filter = reactive({ status: props.filters.status ?? '', type: props.filters.type ?? '' });
const form = useForm({
    name: '', source_type: '', base_url: '', method: 'manual', default_reliability: 'F', owner: '',
    classification: props.classifications.includes('internal') ? 'internal' : (props.classifications[0] ?? ''), description: '',
});

function applyFilters(): void {
    router.get('/sources', Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '')), { preserveScroll: true });
}

function propose(): void {
    form.post('/sources', { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Sources">
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label for="filter-status" class="block text-sm font-medium">Status</label>
                <select id="filter-status" v-model="filter.status" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="status in props.statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
                </select>
            </div>
            <div>
                <label for="filter-type" class="block text-sm font-medium">Type</label>
                <select id="filter-type" v-model="filter.type" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="type in props.types" :key="type" :value="type">{{ typeLabel(type) }}</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <p v-if="props.sources.length === 0" class="mb-8 text-sm text-slate-700">No sources match.</p>
        <div v-else class="mb-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Name</th>
                        <th scope="col" class="py-1 pr-3">Type</th>
                        <th scope="col" class="py-1 pr-3">Domain</th>
                        <th scope="col" class="py-1 pr-3">Method</th>
                        <th scope="col" class="py-1 pr-3">Status</th>
                        <th scope="col" class="py-1 pr-3">Reliability</th>
                        <th scope="col" class="py-1 pr-3">Owner</th>
                        <th scope="col" class="py-1">Classification</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="source in props.sources" :key="source.ulid" class="border-b border-slate-100">
                        <td class="py-1 pr-3"><Link :href="`/sources/${source.ulid}`" class="underline">{{ source.name }}</Link></td>
                        <td class="py-1 pr-3">{{ typeLabel(source.type) }}</td>
                        <td class="py-1 pr-3 break-all">{{ source.host ?? '—' }}</td>
                        <td class="py-1 pr-3">{{ methodLabel(source.method) }}</td>
                        <td class="py-1 pr-3">{{ source.status.replace('_', ' ') }}</td>
                        <td class="py-1 pr-3">{{ source.reliability }}</td>
                        <td class="py-1 pr-3">{{ source.owner ?? '—' }}</td>
                        <td class="py-1">{{ source.classification }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-if="props.canPropose" aria-labelledby="propose-heading" class="max-w-2xl">
            <h2 id="propose-heading" class="mb-1 text-lg font-semibold">Propose a source</h2>
            <p class="mb-3 text-sm text-slate-700">A director reviews the source's terms and policy before anyone may use it. The address is recorded, never visited by the system.</p>
            <form class="space-y-3" novalidate @submit.prevent="propose">
                <div>
                    <label for="source-name" class="block font-medium">Name</label>
                    <input id="source-name" v-model="form.name" type="text" required maxlength="255" :aria-invalid="form.errors.name ? 'true' : undefined" aria-describedby="source-errors" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="source-type" class="block font-medium">Type</label>
                        <select id="source-type" v-model="form.source_type" required :aria-invalid="form.errors.source_type ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option value="" disabled>Choose a type</option>
                            <option v-for="type in props.types" :key="type" :value="type">{{ typeLabel(type) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="source-method" class="block font-medium">Collection method</label>
                        <select id="source-method" v-model="form.method" aria-describedby="source-method-help" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option v-for="method in props.methods" :key="method" :value="method">{{ methodLabel(method) }}</option>
                        </select>
                        <p v-if="props.methods.length === 1" id="source-method-help" class="mt-1 text-xs text-slate-600">Automated collection is switched off; evidence from this source is added by hand.</p>
                    </div>
                </div>
                <div>
                    <label for="source-url" class="block font-medium">Base URL (optional)</label>
                    <input id="source-url" v-model="form.base_url" type="url" inputmode="url" maxlength="2048" placeholder="https://" aria-describedby="source-url-help" :aria-invalid="form.errors.base_url ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <p id="source-url-help" class="mt-1 text-xs text-slate-600">An http(s) address on a domain name. Never include a user name, password or access key.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="source-reliability" class="block font-medium">Default reliability</label>
                        <select id="source-reliability" v-model="form.default_reliability" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option v-for="grade in props.reliabilities" :key="grade" :value="grade">{{ grade === 'F' ? 'F (cannot be judged)' : grade }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="source-owner" class="block font-medium">Owner</label>
                        <select id="source-owner" v-model="form.owner" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option value="">Me</option>
                            <option v-for="owner in props.owners" :key="owner.ulid" :value="owner.ulid">{{ owner.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="source-classification" class="block font-medium">Classification</label>
                        <select id="source-classification" v-model="form.classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option v-for="classification in props.classifications" :key="classification" :value="classification">{{ classification }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="source-description" class="block font-medium">Intended use</label>
                    <textarea id="source-description" v-model="form.description" maxlength="10000" rows="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" id="source-errors" class="text-sm text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Propose</button>
            </form>
        </section>
    </AppLayout>
</template>
