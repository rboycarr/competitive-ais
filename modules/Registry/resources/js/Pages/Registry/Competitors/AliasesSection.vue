<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';

export type Alias = { id: number; alias: string; type: string };

const props = defineProps<{ competitorUlid: string; competitorName: string; aliases: Alias[]; aliasTypes: string[]; canManage: boolean }>();
const form = useForm({ alias: '', alias_type: props.aliasTypes[0] ?? '' });

function add(): void {
    form.post(`/competitors/${props.competitorUlid}/aliases`, { preserveScroll: true, onSuccess: () => form.reset('alias') });
}

function remove(alias: Alias): void {
    if (window.confirm(`Remove the alias “${alias.alias}” from ${props.competitorName}?`)) {
        router.delete(`/competitors/${props.competitorUlid}/aliases/${alias.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <section aria-labelledby="aliases-heading" class="mb-6 max-w-2xl">
        <h2 id="aliases-heading" class="mb-2 text-lg font-semibold">Aliases</h2>
        <ul v-if="props.aliases.length > 0" class="mb-3 space-y-1 text-sm">
            <li v-for="alias in props.aliases" :key="alias.id" class="flex items-center gap-3">
                <span>{{ alias.alias }}</span>
                <span class="text-slate-600">{{ alias.type }}</span>
                <button v-if="props.canManage" type="button" class="underline" :aria-label="`Remove alias ${alias.alias}`" @click="remove(alias)">Remove</button>
            </li>
        </ul>
        <p v-else class="mb-3 text-sm text-slate-600">No aliases recorded.</p>

        <form v-if="props.canManage" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="add">
            <div>
                <label for="alias-name" class="block text-sm font-medium">Alias</label>
                <input id="alias-name" v-model="form.alias" type="text" required maxlength="255" :aria-invalid="form.errors.alias ? 'true' : undefined" aria-describedby="alias-errors" class="mt-1 rounded border border-slate-300 px-2 py-1">
            </div>
            <div>
                <label for="alias-type" class="block text-sm font-medium">Type</label>
                <select id="alias-type" v-model="form.alias_type" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option v-for="type in props.aliasTypes" :key="type" :value="type">{{ type }}</option>
                </select>
            </div>
            <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add alias</button>
            <ul v-if="Object.keys(form.errors).length > 0" id="alias-errors" class="w-full text-sm text-red-700" role="alert">
                <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
            </ul>
        </form>
    </section>
</template>
