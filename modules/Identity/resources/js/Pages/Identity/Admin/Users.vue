<script setup lang="ts">
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Row = {
    ulid: string; name: string; email: string; status: string; clearance: string;
    roles: string[]; twoFactor: boolean; lastLoginAt: string | null; isSelf: boolean;
};
type PendingInvitation = { ulid: string; email: string; role: string; clearance: string; expiresAt: string };

const props = defineProps<{
    users: Row[]; invitations: PendingInvitation[]; roles: string[]; clearances: string[]; canManage: boolean;
}>();

const invite = useForm({ email: '', role: '', clearance: 'internal' });
const editing = reactive<Record<string, string[]>>({});
const roleErrors = reactive<Record<string, string>>({});

function sendInvitation(): void {
    invite.post('/admin/users/invitations', { preserveScroll: true, onSuccess: () => invite.reset('email') });
}

function startEditing(user: Row): void {
    editing[user.ulid] = [...user.roles];
}

function saveRoles(user: Row): void {
    router.put(`/admin/users/${user.ulid}/roles`, { roles: editing[user.ulid] ?? [] }, {
        preserveScroll: true,
        onSuccess: () => { delete editing[user.ulid]; delete roleErrors[user.ulid]; },
        onError: (errors) => { roleErrors[user.ulid] = errors.roles ?? 'Could not save the roles.'; },
    });
}
</script>

<template>
    <AppLayout title="Users">
        <section v-if="props.canManage" aria-labelledby="invite-heading" class="mb-8 max-w-xl">
            <h2 id="invite-heading" class="mb-3 text-lg font-semibold">Invite a user</h2>
            <form class="grid gap-3 sm:grid-cols-3" novalidate @submit.prevent="sendInvitation">
                <div class="sm:col-span-3">
                    <label for="invite-email" class="block font-medium">Email</label>
                    <input
                        id="invite-email" v-model="invite.email" type="email" required
                        :aria-invalid="invite.errors.email ? 'true' : undefined" aria-describedby="invite-error"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    >
                </div>
                <div>
                    <label for="invite-role" class="block font-medium">Role</label>
                    <select id="invite-role" v-model="invite.role" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        <option value="" disabled>Choose…</option>
                        <option v-for="role in props.roles" :key="role" :value="role">{{ role }}</option>
                    </select>
                </div>
                <div>
                    <label for="invite-clearance" class="block font-medium">Clearance</label>
                    <select id="invite-clearance" v-model="invite.clearance" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        <option v-for="level in props.clearances" :key="level" :value="level">{{ level }}</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" :disabled="invite.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Send invitation</button>
                </div>
                <p v-if="invite.errors.email || invite.errors.role || invite.errors.clearance" id="invite-error" class="text-sm text-red-700 sm:col-span-3" role="alert">
                    {{ invite.errors.email || invite.errors.role || invite.errors.clearance }}
                </p>
            </form>
            <ul v-if="props.invitations.length > 0" class="mt-4 text-sm text-slate-700">
                <li v-for="invitation in props.invitations" :key="invitation.ulid">
                    Pending: {{ invitation.email }} ({{ invitation.role }}, {{ invitation.clearance }}), expires {{ new Date(invitation.expiresAt).toUTCString() }}
                </li>
            </ul>
        </section>

        <table class="w-full border-collapse text-left text-sm">
            <caption class="sr-only">All users</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2">Name</th>
                    <th scope="col">Status</th>
                    <th scope="col">Clearance</th>
                    <th scope="col">Two-factor</th>
                    <th scope="col">Roles</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="user in props.users" :key="user.ulid" class="border-b border-slate-200 align-top">
                    <td class="py-2">{{ user.name }}<br><span class="text-slate-600">{{ user.email }}</span></td>
                    <td>{{ user.status }}</td>
                    <td>{{ user.clearance }}</td>
                    <td>{{ user.twoFactor ? 'on' : 'off' }}</td>
                    <td>
                        <template v-if="editing[user.ulid]">
                            <fieldset>
                                <legend class="sr-only">Roles of {{ user.name }}</legend>
                                <label v-for="role in props.roles" :key="role" class="mr-3 inline-flex items-center gap-1">
                                    <input v-model="editing[user.ulid]" type="checkbox" :value="role"> {{ role }}
                                </label>
                            </fieldset>
                            <button type="button" class="mt-2 rounded bg-slate-900 px-3 py-1 text-white" @click="saveRoles(user)">Save</button>
                            <p v-if="roleErrors[user.ulid]" class="mt-1 text-red-700" role="alert">{{ roleErrors[user.ulid] }}</p>
                        </template>
                        <template v-else>
                            {{ user.roles.join(', ') || '—' }}
                            <button v-if="props.canManage && !user.isSelf" type="button" class="ml-2 underline" @click="startEditing(user)">
                                Change<span class="sr-only"> roles of {{ user.name }}</span>
                            </button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </AppLayout>
</template>
