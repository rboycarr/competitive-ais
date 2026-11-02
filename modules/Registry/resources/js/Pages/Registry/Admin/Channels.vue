<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Channel = { id: number; name: string; type: string; retailers: number };

const props = defineProps<{ channels: Channel[]; types: string[] }>();
// A refused removal (retailers use the channel) comes back as a page error.
const page = usePage<{ errors?: Record<string, string> }>();
const removeError = computed(() => page.props.errors?.channel ?? null);

const form = useForm({ name: '', channel_type: props.types[0] ?? '' });

function add(): void {
    form.post('/admin/channels', { preserveScroll: true, onSuccess: () => form.reset('name') });
}

const editing = ref<number | null>(null);
const edit = useForm({ name: '', channel_type: '' });

function startEdit(channel: Channel): void {
    edit.name = channel.name;
    edit.channel_type = channel.type;
    edit.clearErrors();
    editing.value = channel.id;
}

function save(channel: Channel): void {
    edit.put(`/admin/channels/${channel.id}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function remove(channel: Channel): void {
    if (window.confirm(`Remove the channel “${channel.name}”?`)) {
        router.delete(`/admin/channels/${channel.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Channels">
        <p v-if="removeError" class="mb-2 text-sm text-red-700" role="alert">{{ removeError }}</p>
        <table v-if="props.channels.length > 0" class="mb-8 w-full max-w-3xl text-left text-sm">
            <caption class="sr-only">Channels</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2 pr-4">Type</th>
                    <th scope="col" class="py-2 pr-4">Retailers</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="channel in props.channels" :key="channel.id" class="border-b border-slate-200 align-top">
                    <template v-if="editing === channel.id">
                        <td class="py-2 pr-4">
                            <label :for="`edit-name-${channel.id}`" class="sr-only">New name for {{ channel.name }}</label>
                            <input :id="`edit-name-${channel.id}`" v-model="edit.name" type="text" required maxlength="255" class="w-full rounded border border-slate-300 px-2 py-1">
                        </td>
                        <td class="py-2 pr-4">
                            <label :for="`edit-type-${channel.id}`" class="sr-only">Type of {{ channel.name }}</label>
                            <select :id="`edit-type-${channel.id}`" v-model="edit.channel_type" class="rounded border border-slate-300 px-2 py-1">
                                <option v-for="type in props.types" :key="type" :value="type">{{ type }}</option>
                            </select>
                        </td>
                        <td class="py-2 pr-4">{{ channel.retailers }}</td>
                        <td class="py-2">
                            <form class="flex flex-wrap gap-2" novalidate @submit.prevent="save(channel)">
                                <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                                <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                            </form>
                            <ul v-if="Object.keys(edit.errors).length > 0" class="mt-1 text-red-700" role="alert">
                                <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                            </ul>
                        </td>
                    </template>
                    <template v-else>
                        <td class="py-2 pr-4">{{ channel.name }}</td>
                        <td class="py-2 pr-4">{{ channel.type }}</td>
                        <td class="py-2 pr-4">{{ channel.retailers }}</td>
                        <td class="flex gap-3 py-2">
                            <button type="button" class="underline" :aria-label="`Edit channel ${channel.name}`" @click="startEdit(channel)">Edit</button>
                            <button type="button" class="underline" :aria-label="`Remove channel ${channel.name}`" @click="remove(channel)">Remove</button>
                        </td>
                    </template>
                </tr>
            </tbody>
        </table>
        <p v-else class="mb-8 text-sm text-slate-600">No channels yet.</p>

        <section aria-labelledby="add-channel-heading" class="max-w-2xl">
            <h2 id="add-channel-heading" class="mb-2 text-lg font-semibold">Add a channel</h2>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="channel-name" class="block font-medium">Name</label>
                    <input id="channel-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="channel-type" class="block font-medium">Type</label>
                    <select id="channel-type" v-model="form.channel_type" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option v-for="type in props.types" :key="type" :value="type">{{ type }}</option>
                    </select>
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add channel</button>
                <ul v-if="Object.keys(form.errors).length > 0" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
