<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Option = { ulid: string; name: string };
type Brand = { ulid: string; name: string; owner: Option | null };

const props = defineProps<{ brands: Brand[]; canManage: boolean; competitors: Option[] }>();

// Our brands first, then one group per competitor (the server sorts them that way).
const groups = computed(() => {
    const result: { key: string; owner: Option | null; brands: Brand[] }[] = [];
    for (const brand of props.brands) {
        const key = brand.owner?.ulid ?? 'own';
        const last = result[result.length - 1];
        if (last?.key === key) last.brands.push(brand);
        else result.push({ key, owner: brand.owner, brands: [brand] });
    }

    return result;
});

const form = useForm({ competitor: '', name: '' });

function add(): void {
    form.post('/brands', { preserveScroll: true, onSuccess: () => form.reset('name') });
}

const renaming = ref<string | null>(null);
const rename = useForm({ name: '' });

function startRename(brand: Brand): void {
    rename.name = brand.name;
    rename.clearErrors();
    renaming.value = brand.ulid;
}

function saveName(brand: Brand): void {
    rename.put(`/brands/${brand.ulid}`, { preserveScroll: true, onSuccess: () => { renaming.value = null; } });
}

function remove(brand: Brand): void {
    if (window.confirm(`Remove the brand “${brand.name}”?`)) {
        router.delete(`/brands/${brand.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Brands">
        <p v-if="props.brands.length === 0" class="mb-6 text-sm text-slate-600">No brands recorded.</p>
        <section v-for="group in groups" :key="group.key" :aria-labelledby="`brands-${group.key}`" class="mb-6 max-w-2xl">
            <h2 :id="`brands-${group.key}`" class="mb-2 text-lg font-semibold">
                <template v-if="group.owner === null">Our brands</template>
                <Link v-else :href="`/competitors/${group.owner.ulid}`" class="underline">{{ group.owner.name }}</Link>
            </h2>
            <ul class="space-y-1 text-sm">
                <li v-for="brand in group.brands" :key="brand.ulid">
                    <form v-if="renaming === brand.ulid" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="saveName(brand)">
                        <label :for="`rename-${brand.ulid}`" class="sr-only">New name for {{ brand.name }}</label>
                        <input :id="`rename-${brand.ulid}`" v-model="rename.name" type="text" required maxlength="255" class="rounded border border-slate-300 px-2 py-1">
                        <button type="submit" :disabled="rename.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                        <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="renaming = null">Cancel</button>
                        <p v-if="rename.errors.name" class="w-full text-red-700" role="alert">{{ rename.errors.name }}</p>
                    </form>
                    <div v-else class="flex items-center gap-3">
                        <span>{{ brand.name }}</span>
                        <template v-if="props.canManage">
                            <button type="button" class="underline" :aria-label="`Rename brand ${brand.name}`" @click="startRename(brand)">Rename</button>
                            <button type="button" class="underline" :aria-label="`Remove brand ${brand.name}`" @click="remove(brand)">Remove</button>
                        </template>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="props.canManage" aria-labelledby="add-brand-heading" class="max-w-2xl">
            <h2 id="add-brand-heading" class="mb-2 text-lg font-semibold">Add a brand</h2>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="brand-owner" class="block font-medium">Owner</label>
                    <select id="brand-owner" v-model="form.competitor" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option value="">Our own brand</option>
                        <option v-for="competitor in props.competitors" :key="competitor.ulid" :value="competitor.ulid">{{ competitor.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="brand-name" class="block font-medium">Name</label>
                    <input id="brand-name" v-model="form.name" type="text" required maxlength="255" aria-describedby="brand-errors" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add brand</button>
                <ul v-if="Object.keys(form.errors).length > 0" id="brand-errors" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
