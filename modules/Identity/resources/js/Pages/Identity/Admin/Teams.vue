<script setup lang="ts">
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Member = { ulid: string; name: string };
type TeamRow = { ulid: string; name: string; description: string | null; lead: string | null; members: Member[] };

const props = defineProps<{ teams: TeamRow[]; users: Member[] }>();

const create = useForm({ name: '', description: '', lead: '' });
const adding = reactive<Record<string, string>>({});
const errors = reactive<Record<string, string>>({});

function createTeam(): void {
    create.post('/admin/teams', { preserveScroll: true, onSuccess: () => create.reset() });
}

function addMember(team: TeamRow): void {
    router.post(`/admin/teams/${team.ulid}/members`, { member: adding[team.ulid] ?? '' }, {
        preserveScroll: true,
        onSuccess: () => { adding[team.ulid] = ''; delete errors[team.ulid]; },
        onError: (e) => { errors[team.ulid] = e.member ?? 'Could not add the member.'; },
    });
}

function removeMember(team: TeamRow, member: Member): void {
    router.delete(`/admin/teams/${team.ulid}/members/${member.ulid}`, { preserveScroll: true });
}

function leadName(team: TeamRow): string {
    return props.users.find((user) => user.ulid === team.lead)?.name ?? '—';
}
</script>

<template>
    <AppLayout title="Teams">
        <section v-for="team in props.teams" :key="team.ulid" :aria-labelledby="`team-${team.ulid}`" class="mb-6 rounded border border-slate-200 p-4">
            <h2 :id="`team-${team.ulid}`" class="font-semibold">{{ team.name }}</h2>
            <p v-if="team.description" class="text-sm text-slate-700">{{ team.description }}</p>
            <p class="text-sm text-slate-700">Lead: {{ leadName(team) }}</p>
            <ul class="mt-2 text-sm">
                <li v-for="member in team.members" :key="member.ulid" class="flex items-center gap-2">
                    {{ member.name }}
                    <button type="button" class="underline" @click="removeMember(team, member)">
                        Remove<span class="sr-only"> {{ member.name }} from {{ team.name }}</span>
                    </button>
                </li>
            </ul>
            <form class="mt-3 flex items-end gap-2" novalidate @submit.prevent="addMember(team)">
                <div>
                    <label :for="`add-${team.ulid}`" class="block text-sm font-medium">Add member</label>
                    <select :id="`add-${team.ulid}`" v-model="adding[team.ulid]" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option value="" disabled>Choose…</option>
                        <option v-for="user in props.users" :key="user.ulid" :value="user.ulid">{{ user.name }}</option>
                    </select>
                </div>
                <button type="submit" class="rounded bg-slate-900 px-3 py-1 text-white">Add</button>
            </form>
            <p v-if="errors[team.ulid]" class="mt-1 text-sm text-red-700" role="alert">{{ errors[team.ulid] }}</p>
        </section>

        <section aria-labelledby="new-team-heading" class="max-w-xl">
            <h2 id="new-team-heading" class="mb-2 text-lg font-semibold">New team</h2>
            <form class="space-y-3" novalidate @submit.prevent="createTeam">
                <div>
                    <label for="team-name" class="block font-medium">Name</label>
                    <input
                        id="team-name" v-model="create.name" type="text" required maxlength="100"
                        :aria-invalid="create.errors.name ? 'true' : undefined" aria-describedby="team-error"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    >
                </div>
                <div>
                    <label for="team-description" class="block font-medium">Description</label>
                    <textarea id="team-description" v-model="create.description" maxlength="500" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label for="team-lead" class="block font-medium">Lead</label>
                    <select id="team-lead" v-model="create.lead" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        <option value="">No lead</option>
                        <option v-for="user in props.users" :key="user.ulid" :value="user.ulid">{{ user.name }}</option>
                    </select>
                </div>
                <p v-if="create.errors.name || create.errors.lead" id="team-error" class="text-sm text-red-700" role="alert">{{ create.errors.name || create.errors.lead }}</p>
                <button type="submit" :disabled="create.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">Create team</button>
            </form>
        </section>
    </AppLayout>
</template>
