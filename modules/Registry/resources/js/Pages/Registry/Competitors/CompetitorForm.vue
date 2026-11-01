<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

export type CompetitorFields = {
    name: string; legal_name: string; website: string; hq_country: string; tier: string; analyst: string; classification: string; description: string;
};
type Option = { ulid: string; name: string };

// The form owns its state; the page passes the starting values and where to send them.
const props = defineProps<{
    initial: CompetitorFields; method: 'post' | 'put'; url: string; idPrefix: string; tiers: string[]; analysts: Option[]; classifications: string[]; submitLabel: string;
}>();
const emit = defineEmits<{ saved: [] }>();
const form = useForm<CompetitorFields>({ ...props.initial });

function submit(): void {
    form.submit(props.method, props.url, { preserveScroll: true, onSuccess: () => emit('saved') });
}
</script>

<template>
    <form class="space-y-3" novalidate @submit.prevent="submit">
        <div>
            <label :for="`${idPrefix}-name`" class="block font-medium">Name</label>
            <input :id="`${idPrefix}-name`" v-model="form.name" type="text" required maxlength="255" :aria-invalid="form.errors.name ? 'true' : undefined" :aria-describedby="`${idPrefix}-errors`" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <div>
            <label :for="`${idPrefix}-legal`" class="block font-medium">Legal name</label>
            <input :id="`${idPrefix}-legal`" v-model="form.legal_name" type="text" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label :for="`${idPrefix}-website`" class="block font-medium">Website</label>
                <input :id="`${idPrefix}-website`" v-model="form.website" type="url" inputmode="url" maxlength="2048" placeholder="https://" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label :for="`${idPrefix}-country`" class="block font-medium">Headquarters country (ISO code)</label>
                <input :id="`${idPrefix}-country`" v-model="form.hq_country" type="text" maxlength="2" autocapitalize="characters" class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 uppercase">
            </div>
            <div>
                <label :for="`${idPrefix}-tier`" class="block font-medium">Tier</label>
                <select :id="`${idPrefix}-tier`" v-model="form.tier" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option value="">Not set</option>
                    <option v-for="tier in tiers" :key="tier" :value="tier">{{ tier }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-analyst`" class="block font-medium">Assigned analyst</label>
                <select :id="`${idPrefix}-analyst`" v-model="form.analyst" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option value="">Nobody</option>
                    <option v-for="analyst in analysts" :key="analyst.ulid" :value="analyst.ulid">{{ analyst.name }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-classification`" class="block font-medium">Classification</label>
                <select :id="`${idPrefix}-classification`" v-model="form.classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option v-for="classification in classifications" :key="classification" :value="classification">{{ classification }}</option>
                </select>
            </div>
        </div>
        <div>
            <label :for="`${idPrefix}-description`" class="block font-medium">Description</label>
            <textarea :id="`${idPrefix}-description`" v-model="form.description" maxlength="10000" rows="4" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
        </div>
        <ul v-if="Object.keys(form.errors).length > 0" :id="`${idPrefix}-errors`" class="text-sm text-red-700" role="alert">
            <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
        <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">{{ submitLabel }}</button>
    </form>
</template>
