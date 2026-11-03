<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { statusLabel } from './labels';

type Row = { ulid: string; title: string; status: string; capturedAt: string; uploadedBy: string | null; classification: string };

const props = defineProps<{
    assets: Row[]; filters: { status?: string }; statuses: string[]; canUpload: boolean; classifications: string[]; maxBytes: number;
}>();

const filter = reactive({ status: props.filters.status ?? '' });
const form = useForm<{ file: File | null; title: string; description: string; source_url: string; captured_at: string; classification: string }>({
    file: null, title: '', description: '', source_url: '', captured_at: '',
    classification: props.classifications.includes('internal') ? 'internal' : (props.classifications[0] ?? ''),
});
const maxMegabytes = Math.floor(props.maxBytes / (1024 * 1024));

function applyFilters(): void {
    router.get('/evidence', filter.status === '' ? {} : { status: filter.status }, { preserveScroll: true });
}

function chooseFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    // The browser gives local time; the server stores UTC.
    form.transform((data) => ({ ...data, captured_at: data.captured_at === '' ? '' : new Date(data.captured_at).toISOString() }))
        .post('/evidence', { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Evidence">
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label for="filter-status" class="block text-sm font-medium">Status</label>
                <select id="filter-status" v-model="filter.status" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="status in props.statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <p v-if="props.assets.length === 0" class="mb-8 text-sm text-slate-700">No evidence matches.</p>
        <div v-else class="mb-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Title</th>
                        <th scope="col" class="py-1 pr-3">Status</th>
                        <th scope="col" class="py-1 pr-3">Captured</th>
                        <th scope="col" class="py-1 pr-3">Uploaded by</th>
                        <th scope="col" class="py-1">Classification</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="asset in props.assets" :key="asset.ulid" class="border-b border-slate-100">
                        <td class="py-1 pr-3"><Link :href="`/evidence/${asset.ulid}`" class="underline">{{ asset.title }}</Link></td>
                        <td class="py-1 pr-3">{{ statusLabel(asset.status) }}</td>
                        <td class="py-1 pr-3">{{ new Date(asset.capturedAt).toLocaleString() }}</td>
                        <td class="py-1 pr-3">{{ asset.uploadedBy ?? '—' }}</td>
                        <td class="py-1">{{ asset.classification }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-if="props.canUpload" aria-labelledby="upload-heading" class="max-w-2xl">
            <h2 id="upload-heading" class="mb-1 text-lg font-semibold">Upload evidence</h2>
            <p class="mb-3 text-sm text-slate-700">The file stays in quarantine until it has been scanned; only you and directors see it until then. Accepted: PDF, PNG, JPEG, WebP, text, CSV, DOCX, XLSX, up to {{ maxMegabytes }} MB.</p>
            <form class="space-y-3" novalidate @submit.prevent="upload">
                <div>
                    <label for="evidence-file" class="block font-medium">File</label>
                    <input id="evidence-file" type="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.docx,.xlsx" :aria-invalid="form.errors.file ? 'true' : undefined" class="mt-1 block w-full text-sm" @change="chooseFile">
                </div>
                <div>
                    <label for="evidence-title" class="block font-medium">Title</label>
                    <input id="evidence-title" v-model="form.title" type="text" required maxlength="255" :aria-invalid="form.errors.title ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="evidence-captured" class="block font-medium">Captured</label>
                        <input id="evidence-captured" v-model="form.captured_at" type="datetime-local" required :aria-invalid="form.errors.captured_at ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="evidence-classification" class="block font-medium">Classification</label>
                        <select id="evidence-classification" v-model="form.classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                            <option v-for="classification in props.classifications" :key="classification" :value="classification">{{ classification }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="evidence-source-url" class="block font-medium">Where it was found (optional)</label>
                    <input id="evidence-source-url" v-model="form.source_url" type="url" inputmode="url" maxlength="2048" placeholder="https://" aria-describedby="evidence-source-url-help" :aria-invalid="form.errors.source_url ? 'true' : undefined" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <p id="evidence-source-url-help" class="mt-1 text-xs text-slate-600">An http(s) address. It is recorded, never visited by the system. Never include a user name or password.</p>
                </div>
                <div>
                    <label for="evidence-description" class="block font-medium">Description (optional)</label>
                    <textarea id="evidence-description" v-model="form.description" maxlength="10000" rows="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" class="text-sm text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <progress v-if="form.progress" :value="form.progress.percentage" max="100" class="w-full" aria-label="Upload progress">{{ form.progress.percentage }}%</progress>
                <button type="submit" :disabled="form.processing || form.file === null" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Upload</button>
            </form>
        </section>
    </AppLayout>
</template>
