<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';

type Option = { ulid: string; name: string };
export type Relationship = {
    ulid: string; type: string; role: 'parent' | 'child'; other: Option; validFrom: string; validFromPrecision: string; validTo: string | null; notes: string | null;
};

const props = defineProps<{
    competitorUlid: string; relationships: Relationship[]; relationTypes: string[]; precisions: string[]; others: Option[]; canManage: boolean;
}>();

// How this competitor relates to the other one; the parent is the owner or principal (docs/02).
const PHRASES: Record<string, string> = {
    'subsidiary:parent': 'Is the parent company of',
    'subsidiary:child': 'Is a subsidiary of',
    'brand_owner:parent': 'Owns the brand',
    'brand_owner:child': 'Is a brand owned by',
    'joint_venture:parent': 'Is an owner of the joint venture',
    'joint_venture:child': 'Is a joint venture owned in part by',
    'partner:parent': 'Is a partner of',
    'partner:child': 'Is a partner of',
    'distributor:parent': 'Is distributed by',
    'distributor:child': 'Is a distributor for',
};
const kinds = computed(() => Object.keys(PHRASES).filter((kind) => kind !== 'partner:child' && props.relationTypes.includes(kind.split(':')[0] ?? '')));

function phrase(relationship: Relationship): string {
    return PHRASES[`${relationship.type}:${relationship.role}`] ?? relationship.type.replace('_', ' ');
}

const PRECISIONS: Record<string, string> = { day: 'Exact day', month: 'Month only', year: 'Year only', unknown: 'Not known (earliest known date)' };

function since(relationship: Relationship): string {
    const { validFrom, validFromPrecision } = relationship;
    if (validFromPrecision === 'year') return validFrom.slice(0, 4);
    if (validFromPrecision === 'month') return validFrom.slice(0, 7);

    return validFromPrecision === 'unknown' ? `${validFrom} (earliest known)` : validFrom;
}

const form = useForm({ kind: kinds.value[0] ?? '', other: '', valid_from: '', valid_from_precision: 'day', valid_to: '', notes: '' });

function add(): void {
    const [relationType, role] = form.kind.split(':');
    form.transform((data) => ({
        role, relation_type: relationType, other: data.other, valid_from: data.valid_from, valid_from_precision: data.valid_from_precision, valid_to: data.valid_to, notes: data.notes,
    })).post(`/competitors/${props.competitorUlid}/relationships`, { preserveScroll: true, onSuccess: () => form.reset() });
}

const editing = ref<string | null>(null);
const edit = useForm({ valid_from: '', valid_from_precision: 'day', valid_to: '', notes: '' });

function startEdit(relationship: Relationship): void {
    Object.assign(edit, { valid_from: relationship.validFrom, valid_from_precision: relationship.validFromPrecision, valid_to: relationship.validTo ?? '', notes: relationship.notes ?? '' });
    edit.clearErrors();
    editing.value = relationship.ulid;
}

function save(relationship: Relationship): void {
    edit.put(`/competitors/${props.competitorUlid}/relationships/${relationship.ulid}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function remove(relationship: Relationship): void {
    if (window.confirm(`Remove “${phrase(relationship)} ${relationship.other.name}”? Use an end date if the relationship ended; remove only a mistake.`)) {
        router.delete(`/competitors/${props.competitorUlid}/relationships/${relationship.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <section aria-labelledby="relationships-heading" class="mb-6 max-w-2xl">
        <h2 id="relationships-heading" class="mb-2 text-lg font-semibold">Relationships</h2>
        <ul v-if="props.relationships.length > 0" class="mb-3 space-y-3 text-sm">
            <li v-for="relationship in props.relationships" :key="relationship.ulid">
                <p>
                    {{ phrase(relationship) }} <Link :href="`/competitors/${relationship.other.ulid}`" class="underline">{{ relationship.other.name }}</Link>
                    <span class="text-slate-600"> · from {{ since(relationship) }} to {{ relationship.validTo ?? 'present' }}</span>
                </p>
                <p v-if="relationship.notes" class="whitespace-pre-line text-slate-600">{{ relationship.notes }}</p>
                <div v-if="props.canManage && editing !== relationship.ulid" class="mt-1 flex gap-3">
                    <button type="button" class="underline" :aria-label="`Edit dates of: ${phrase(relationship)} ${relationship.other.name}`" @click="startEdit(relationship)">Edit dates</button>
                    <button type="button" class="underline" :aria-label="`Remove: ${phrase(relationship)} ${relationship.other.name}`" @click="remove(relationship)">Remove</button>
                </div>
                <form v-if="editing === relationship.ulid" class="mt-2 grid gap-2 sm:grid-cols-3" novalidate @submit.prevent="save(relationship)">
                    <div>
                        <label for="edit-relationship-from" class="block font-medium">From</label>
                        <input id="edit-relationship-from" v-model="edit.valid_from" type="date" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    </div>
                    <div>
                        <label for="edit-relationship-precision" class="block font-medium">Start date precision</label>
                        <select id="edit-relationship-precision" v-model="edit.valid_from_precision" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                            <option v-for="precision in props.precisions" :key="precision" :value="precision">{{ PRECISIONS[precision] ?? precision }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit-relationship-to" class="block font-medium">To (empty: ongoing)</label>
                        <input id="edit-relationship-to" v-model="edit.valid_to" type="date" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    </div>
                    <div class="sm:col-span-3">
                        <label for="edit-relationship-notes" class="block font-medium">Notes</label>
                        <textarea id="edit-relationship-notes" v-model="edit.notes" maxlength="2000" rows="2" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" />
                    </div>
                    <ul v-if="Object.keys(edit.errors).length > 0" class="text-red-700 sm:col-span-3" role="alert">
                        <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                    </ul>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save dates</button>
                        <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                    </div>
                </form>
            </li>
        </ul>
        <p v-else class="mb-3 text-sm text-slate-600">No relationships recorded.</p>

        <form v-if="props.canManage && props.others.length > 0" class="grid gap-2 text-sm sm:grid-cols-2" novalidate @submit.prevent="add">
            <div>
                <label for="relationship-kind" class="block font-medium">This competitor</label>
                <select id="relationship-kind" v-model="form.kind" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option v-for="kind in kinds" :key="kind" :value="kind">{{ PHRASES[kind] }}</option>
                </select>
            </div>
            <div>
                <label for="relationship-other" class="block font-medium">Competitor</label>
                <select id="relationship-other" v-model="form.other" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option value="" disabled>Choose…</option>
                    <option v-for="other in props.others" :key="other.ulid" :value="other.ulid">{{ other.name }}</option>
                </select>
            </div>
            <div>
                <label for="relationship-from" class="block font-medium">From (earliest known date)</label>
                <input id="relationship-from" v-model="form.valid_from" type="date" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            </div>
            <div>
                <label for="relationship-precision" class="block font-medium">Start date precision</label>
                <select id="relationship-precision" v-model="form.valid_from_precision" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option v-for="precision in props.precisions" :key="precision" :value="precision">{{ PRECISIONS[precision] ?? precision }}</option>
                </select>
            </div>
            <div>
                <label for="relationship-to" class="block font-medium">To (empty: ongoing)</label>
                <input id="relationship-to" v-model="form.valid_to" type="date" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            </div>
            <div class="sm:col-span-2">
                <label for="relationship-notes" class="block font-medium">Notes</label>
                <textarea id="relationship-notes" v-model="form.notes" maxlength="2000" rows="2" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" />
            </div>
            <ul v-if="Object.keys(form.errors).length > 0" class="text-red-700 sm:col-span-2" role="alert">
                <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
            </ul>
            <div class="sm:col-span-2">
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add relationship</button>
            </div>
        </form>
    </section>
</template>
