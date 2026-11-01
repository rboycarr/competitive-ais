<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Depth first, siblings by name (the server orders them); `path` lists the ancestors' IDs, e.g. "/12/45/".
type Category = { id: number; parentId: number | null; name: string; depth: number; path: string };

const props = defineProps<{ categories: Category[]; maxDepth: number }>();
// A refused removal (e.g. subcategories left) comes back as a page error.
const page = usePage<{ errors?: Record<string, string> }>();
const removeError = computed(() => page.props.errors?.category ?? null);

function label(category: Category): string {
    return `${'— '.repeat(category.depth)}${category.name}`;
}

function isWithin(candidate: Category, category: Category): boolean {
    return candidate.id === category.id || candidate.path.startsWith(`${category.path}${category.id}/`);
}

const parents = computed(() => props.categories.filter((c) => c.depth < props.maxDepth));
const form = useForm<{ name: string; parent: number | '' }>({ name: '', parent: '' });

function add(): void {
    form.transform((data) => ({ name: data.name, parent: data.parent === '' ? null : data.parent }))
        .post('/admin/categories', { preserveScroll: true, onSuccess: () => form.reset('name') });
}

const editing = ref<{ id: number; mode: 'rename' | 'move' } | null>(null);
const edit = useForm<{ name: string; parent: number | '' }>({ name: '', parent: '' });

function start(category: Category, mode: 'rename' | 'move'): void {
    edit.name = category.name;
    edit.parent = category.parentId ?? '';
    edit.clearErrors();
    editing.value = { id: category.id, mode };
}

function save(category: Category): void {
    const done = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    if (editing.value?.mode === 'rename') {
        edit.transform((data) => ({ name: data.name })).put(`/admin/categories/${category.id}`, done);
    } else {
        edit.transform((data) => ({ parent: data.parent === '' ? null : data.parent })).put(`/admin/categories/${category.id}/parent`, done);
    }
}

function remove(category: Category): void {
    if (window.confirm(`Remove the category “${category.name}”?`)) {
        router.delete(`/admin/categories/${category.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Categories">
        <section aria-labelledby="tree-heading" class="mb-6 max-w-2xl">
            <h2 id="tree-heading" class="mb-2 text-lg font-semibold">Product categories</h2>
            <p v-if="removeError" class="mb-2 text-sm text-red-700" role="alert">{{ removeError }}</p>
            <ul v-if="props.categories.length > 0" class="space-y-1 text-sm">
                <li v-for="category in props.categories" :key="category.id" :style="{ paddingLeft: `${category.depth * 1.5}rem` }">
                    <form v-if="editing?.id === category.id" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="save(category)">
                        <template v-if="editing.mode === 'rename'">
                            <label :for="`rename-${category.id}`" class="sr-only">New name for {{ category.name }}</label>
                            <input :id="`rename-${category.id}`" v-model="edit.name" type="text" required maxlength="255" class="rounded border border-slate-300 px-2 py-1">
                        </template>
                        <template v-else>
                            <label :for="`move-${category.id}`" class="sr-only">New place for {{ category.name }}</label>
                            <select :id="`move-${category.id}`" v-model="edit.parent" class="rounded border border-slate-300 px-2 py-1">
                                <option value="">Top level</option>
                                <option v-for="parent in parents.filter((p) => !isWithin(p, category))" :key="parent.id" :value="parent.id">{{ label(parent) }}</option>
                            </select>
                        </template>
                        <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                        <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                        <ul v-if="Object.keys(edit.errors).length > 0" class="w-full text-red-700" role="alert">
                            <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                        </ul>
                    </form>
                    <div v-else class="flex items-center gap-3">
                        <span>{{ category.name }}</span>
                        <button type="button" class="underline" :aria-label="`Rename category ${category.name}`" @click="start(category, 'rename')">Rename</button>
                        <button type="button" class="underline" :aria-label="`Move category ${category.name}`" @click="start(category, 'move')">Move</button>
                        <button type="button" class="underline" :aria-label="`Remove category ${category.name}`" @click="remove(category)">Remove</button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-slate-600">No categories yet.</p>
        </section>

        <section aria-labelledby="add-category-heading" class="max-w-2xl">
            <h2 id="add-category-heading" class="mb-2 text-lg font-semibold">Add a category</h2>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="category-name" class="block font-medium">Name</label>
                    <input id="category-name" v-model="form.name" type="text" required maxlength="255" aria-describedby="category-errors" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="category-parent" class="block font-medium">Under</label>
                    <select id="category-parent" v-model="form.parent" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option value="">Top level</option>
                        <option v-for="parent in parents" :key="parent.id" :value="parent.id">{{ label(parent) }}</option>
                    </select>
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add category</button>
                <ul v-if="Object.keys(form.errors).length > 0" id="category-errors" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
