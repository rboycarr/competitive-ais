<script setup lang="ts">
// The item form (P1-40), new or edited. Offers only what the writer can see; the server decides everything again.
import { useForm } from '@inertiajs/vue3';
import { typeLabel } from '../Pages/Intelligence/labels';

type Option = { ulid: string; name: string };
export type ItemFields = {
    title: string; summary: string; item_type: string; classification: string; high_impact: boolean;
    observed_at: string | null; published_at: string | null; collected_at: string | null;
    competitors: string[]; products: string[]; markets: string[];
};

const props = defineProps<{
    id: string; initial: ItemFields; version?: number; action: string; submitLabel: string;
    types: string[]; classifications: string[]; competitorOptions: Option[]; productOptions: Option[]; marketOptions: Option[];
}>();

// Dates are entered and sent in UTC; the input shows minutes only.
const minutes = (iso: string | null): string => (iso ?? '').slice(0, 16);
const form = useForm({
    ...props.initial,
    observed_at: minutes(props.initial.observed_at),
    published_at: minutes(props.initial.published_at),
    collected_at: minutes(props.initial.collected_at),
});

function save(): void {
    const options = { preserveScroll: true };
    if (props.version === undefined) {
        form.post(props.action, options);
    } else {
        form.transform((data) => ({ ...data, version: props.version })).put(props.action, options);
    }
}

const DATES = [
    ['observed_at', 'Observed (when it happened)'],
    ['published_at', 'Published by the source'],
    ['collected_at', 'Collected'],
] as const;
const SUBJECTS = [
    ['competitors', 'Competitors', 'competitorOptions'],
    ['products', 'Products', 'productOptions'],
    ['markets', 'Markets', 'marketOptions'],
] as const;
</script>

<template>
    <form class="space-y-3" novalidate @submit.prevent="save">
        <div>
            <label :for="`${props.id}-title`" class="block font-medium">Title</label>
            <input :id="`${props.id}-title`" v-model="form.title" type="text" required maxlength="255" :aria-invalid="form.errors.title ? 'true' : undefined" :aria-describedby="`${props.id}-errors`" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <div>
            <label :for="`${props.id}-summary`" class="block font-medium">Summary</label>
            <textarea :id="`${props.id}-summary`" v-model="form.summary" maxlength="20000" rows="6" :aria-describedby="`${props.id}-summary-help`" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm" />
            <p :id="`${props.id}-summary-help`" class="mt-1 text-xs text-slate-600">Markdown: headings, lists, emphasis, tables and http(s) links. Images and HTML are not shown.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label :for="`${props.id}-type`" class="block font-medium">Type</label>
                <select :id="`${props.id}-type`" v-model="form.item_type" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option value="" disabled>Choose a type</option>
                    <option v-for="type in props.types" :key="type" :value="type">{{ typeLabel(type) }}</option>
                </select>
            </div>
            <div>
                <label :for="`${props.id}-classification`" class="block font-medium">Classification</label>
                <select :id="`${props.id}-classification`" v-model="form.classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option v-for="classification in props.classifications" :key="classification" :value="classification">{{ classification }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 pb-2">
                    <input v-model="form.high_impact" type="checkbox" class="h-4 w-4">
                    <span class="font-medium">High impact</span>
                </label>
            </div>
        </div>
        <fieldset class="grid gap-3 sm:grid-cols-3">
            <legend class="mb-1 font-medium">Dates (UTC, optional)</legend>
            <div v-for="[field, label] in DATES" :key="field">
                <label :for="`${props.id}-${field}`" class="block text-sm">{{ label }}</label>
                <input :id="`${props.id}-${field}`" v-model="form[field]" type="datetime-local" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            </div>
        </fieldset>
        <fieldset class="grid gap-3 sm:grid-cols-3">
            <legend class="mb-1 font-medium">What it is about (at least one)</legend>
            <div v-for="[field, label, options] in SUBJECTS" :key="field">
                <label :for="`${props.id}-${field}`" class="block text-sm">{{ label }}</label>
                <select :id="`${props.id}-${field}`" v-model="form[field]" multiple size="5" :aria-describedby="`${props.id}-subjects-help`" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option v-for="option in props[options]" :key="option.ulid" :value="option.ulid">{{ option.name }}</option>
                </select>
            </div>
            <p :id="`${props.id}-subjects-help`" class="text-xs text-slate-600 sm:col-span-3">A competitor's product brings in its competitor. Only people with access to every competitor and market chosen can see the item.</p>
        </fieldset>
        <ul v-if="Object.keys(form.errors).length > 0" :id="`${props.id}-errors`" class="text-sm text-red-700" role="alert">
            <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
        <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">{{ props.submitLabel }}</button>
    </form>
</template>
