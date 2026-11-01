<script setup lang="ts">
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type RoleRow = { name: string; permissions: string[]; members: number; editable: boolean };

const props = defineProps<{ roles: RoleRow[]; permissions: string[]; privileged: string[] }>();

const editing = reactive<Record<string, string[]>>({});
const errors = reactive<Record<string, string>>({});
const create = useForm({ name: '', permissions: [] as string[] });

function save(role: RoleRow): void {
    router.put(`/admin/roles/${role.name}/permissions`, { permissions: editing[role.name] ?? [] }, {
        preserveScroll: true,
        onSuccess: () => { delete editing[role.name]; delete errors[role.name]; },
        onError: (e) => { errors[role.name] = e.permissions ?? 'Could not save the permissions.'; },
    });
}

function createRole(): void {
    create.post('/admin/roles', { preserveScroll: true, onSuccess: () => create.reset() });
}
</script>

<template>
    <AppLayout title="Roles">
        <p class="mb-6 text-slate-700">
            Permissions marked with * need two-factor authentication. The super admin role is fixed, and you cannot change a role you hold.
        </p>

        <section v-for="role in props.roles" :key="role.name" :aria-labelledby="`role-${role.name}`" class="mb-6 rounded border border-slate-200 p-4">
            <h2 :id="`role-${role.name}`" class="font-semibold">{{ role.name }} <span class="font-normal text-slate-600">({{ role.members }} users)</span></h2>
            <template v-if="editing[role.name]">
                <fieldset class="mt-2 grid gap-1 sm:grid-cols-3">
                    <legend class="sr-only">Permissions of {{ role.name }}</legend>
                    <label v-for="permission in props.permissions" :key="permission" class="inline-flex items-center gap-1 text-sm">
                        <input v-model="editing[role.name]" type="checkbox" :value="permission">
                        {{ permission }}{{ props.privileged.includes(permission) ? ' *' : '' }}
                    </label>
                </fieldset>
                <button type="button" class="mt-3 rounded bg-slate-900 px-3 py-1 text-white" @click="save(role)">Save</button>
                <p v-if="errors[role.name]" class="mt-1 text-red-700" role="alert">{{ errors[role.name] }}</p>
            </template>
            <template v-else>
                <p class="mt-1 text-sm text-slate-700">{{ role.permissions.join(', ') || 'No permissions' }}</p>
                <button v-if="role.editable" type="button" class="mt-2 underline" @click="editing[role.name] = [...role.permissions]">
                    Change<span class="sr-only"> permissions of {{ role.name }}</span>
                </button>
            </template>
        </section>

        <section aria-labelledby="new-role-heading" class="max-w-xl">
            <h2 id="new-role-heading" class="mb-2 text-lg font-semibold">New role</h2>
            <form novalidate @submit.prevent="createRole">
                <label for="role-name" class="block font-medium">Name (lower case, e.g. regional_analyst)</label>
                <input
                    id="role-name" v-model="create.name" type="text" required
                    :aria-invalid="create.errors.name ? 'true' : undefined" aria-describedby="role-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                <fieldset class="mt-3 grid gap-1 sm:grid-cols-2">
                    <legend class="font-medium">Permissions</legend>
                    <label v-for="permission in props.permissions" :key="permission" class="inline-flex items-center gap-1 text-sm">
                        <input v-model="create.permissions" type="checkbox" :value="permission">
                        {{ permission }}{{ props.privileged.includes(permission) ? ' *' : '' }}
                    </label>
                </fieldset>
                <p v-if="create.errors.name || create.errors.permissions" id="role-error" class="mt-2 text-sm text-red-700" role="alert">
                    {{ create.errors.name || create.errors.permissions }}
                </p>
                <button type="submit" :disabled="create.processing" class="mt-3 rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Create role</button>
            </form>
        </section>
    </AppLayout>
</template>
