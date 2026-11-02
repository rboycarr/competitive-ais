<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

export type Tag = { id: number; taxonomy: string; name: string };

const props = defineProps<{ competitorUlid: string; competitorName: string; tags: Tag[]; tagOptions: Tag[]; canManage: boolean }>();
const available = computed(() => props.tagOptions.filter((option) => !props.tags.some((tag) => tag.id === option.id)));
const form = useForm<{ tag: number | '' }>({ tag: '' });

function add(): void {
    form.post(`/competitors/${props.competitorUlid}/tags`, { preserveScroll: true, onSuccess: () => form.reset() });
}

function remove(tag: Tag): void {
    if (window.confirm(`Remove the tag “${tag.name}” from ${props.competitorName}?`)) {
        router.delete(`/competitors/${props.competitorUlid}/tags/${tag.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <section aria-labelledby="tags-heading" class="mb-6 max-w-2xl">
        <h2 id="tags-heading" class="mb-2 text-lg font-semibold">Tags</h2>
        <ul v-if="props.tags.length > 0" class="mb-3 space-y-1 text-sm">
            <li v-for="tag in props.tags" :key="tag.id" class="flex items-center gap-3">
                <span>{{ tag.name }}</span>
                <span class="text-slate-600">{{ tag.taxonomy }}</span>
                <button v-if="props.canManage" type="button" class="underline" :aria-label="`Remove tag ${tag.name}`" @click="remove(tag)">Remove</button>
            </li>
        </ul>
        <p v-else class="mb-3 text-sm text-slate-600">No tags.</p>

        <form v-if="props.canManage && available.length > 0" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="add">
            <div>
                <label for="tag-choice" class="block text-sm font-medium">Tag</label>
                <select id="tag-choice" v-model="form.tag" required class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="" disabled>Choose…</option>
                    <option v-for="option in available" :key="option.id" :value="option.id">{{ option.taxonomy }} · {{ option.name }}</option>
                </select>
            </div>
            <button type="submit" :disabled="form.processing || form.tag === ''" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add tag</button>
            <ul v-if="Object.keys(form.errors).length > 0" class="w-full text-sm text-red-700" role="alert">
                <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
            </ul>
        </form>
    </section>
</template>
