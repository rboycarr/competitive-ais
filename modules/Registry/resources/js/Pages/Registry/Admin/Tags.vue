<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Ordered by taxonomy, then name (the server orders them).
type Tag = { id: number; taxonomy: string; name: string };

const props = defineProps<{ tags: Tag[] }>();
// A refused removal (the tag is in use) comes back as a page error.
const page = usePage<{ errors?: Record<string, string> }>();
const removeError = computed(() => page.props.errors?.tag ?? null);
const taxonomies = computed(() => [...new Set(props.tags.map((t) => t.taxonomy))]);

const form = useForm({ taxonomy: '', name: '' });

function add(): void {
    form.post('/admin/tags', { preserveScroll: true, onSuccess: () => form.reset('name') });
}

const editing = ref<number | null>(null);
const edit = useForm({ taxonomy: '', name: '' });

function startEdit(tag: Tag): void {
    edit.taxonomy = tag.taxonomy;
    edit.name = tag.name;
    edit.clearErrors();
    editing.value = tag.id;
}

function save(tag: Tag): void {
    edit.put(`/admin/tags/${tag.id}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function remove(tag: Tag): void {
    if (window.confirm(`Remove the tag “${tag.name}” (${tag.taxonomy})?`)) {
        router.delete(`/admin/tags/${tag.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Tags">
        <p v-if="removeError" class="mb-2 text-sm text-red-700" role="alert">{{ removeError }}</p>
        <table v-if="props.tags.length > 0" class="mb-8 w-full max-w-3xl text-left text-sm">
            <caption class="sr-only">Tags</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">Taxonomy</th>
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="tag in props.tags" :key="tag.id" class="border-b border-slate-200 align-top">
                    <template v-if="editing === tag.id">
                        <td class="py-2 pr-4">
                            <label :for="`edit-taxonomy-${tag.id}`" class="sr-only">Taxonomy of {{ tag.name }}</label>
                            <input :id="`edit-taxonomy-${tag.id}`" v-model="edit.taxonomy" type="text" required maxlength="50" class="w-full rounded border border-slate-300 px-2 py-1 lowercase">
                        </td>
                        <td class="py-2 pr-4">
                            <label :for="`edit-name-${tag.id}`" class="sr-only">New name for {{ tag.name }}</label>
                            <input :id="`edit-name-${tag.id}`" v-model="edit.name" type="text" required maxlength="255" class="w-full rounded border border-slate-300 px-2 py-1">
                        </td>
                        <td class="py-2">
                            <form class="flex flex-wrap gap-2" novalidate @submit.prevent="save(tag)">
                                <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                                <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                            </form>
                            <ul v-if="Object.keys(edit.errors).length > 0" class="mt-1 text-red-700" role="alert">
                                <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                            </ul>
                        </td>
                    </template>
                    <template v-else>
                        <td class="py-2 pr-4">{{ tag.taxonomy }}</td>
                        <td class="py-2 pr-4">{{ tag.name }}</td>
                        <td class="flex gap-3 py-2">
                            <button type="button" class="underline" :aria-label="`Edit tag ${tag.name}`" @click="startEdit(tag)">Edit</button>
                            <button type="button" class="underline" :aria-label="`Remove tag ${tag.name}`" @click="remove(tag)">Remove</button>
                        </td>
                    </template>
                </tr>
            </tbody>
        </table>
        <p v-else class="mb-8 text-sm text-slate-600">No tags yet.</p>

        <section aria-labelledby="add-tag-heading" class="max-w-2xl">
            <h2 id="add-tag-heading" class="mb-2 text-lg font-semibold">Add a tag</h2>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="tag-taxonomy" class="block font-medium">Taxonomy (e.g. segment)</label>
                    <input id="tag-taxonomy" v-model="form.taxonomy" type="text" required maxlength="50" list="tag-taxonomies" class="mt-1 rounded border border-slate-300 px-2 py-1 lowercase">
                    <datalist id="tag-taxonomies">
                        <option v-for="taxonomy in taxonomies" :key="taxonomy" :value="taxonomy" />
                    </datalist>
                </div>
                <div>
                    <label for="tag-name" class="block font-medium">Name</label>
                    <input id="tag-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add tag</button>
                <ul v-if="Object.keys(form.errors).length > 0" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
