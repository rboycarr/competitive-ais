<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Option = { ulid: string; name: string };
type AuditRow = {
    ulid: string; occurredAt: string; actor: string; action: string; subjectType: string | null; subjectUlid: string | null;
    classification: string; requestId: string | null; context: Record<string, unknown>;
};
type Filters = { actor?: string; action?: string; subject?: string; from?: string; to?: string };

const props = defineProps<{
    events: AuditRow[]; nextUrl: string | null; previousUrl: string | null; filters: Filters; actions: string[]; users: Option[];
}>();

// The time fields hold local time; the server filters in UTC.
function toLocalInput(iso: string | undefined): string {
    if (iso === undefined) {
        return '';
    }
    const date = new Date(iso);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

const form = reactive({
    actor: props.filters.actor ?? '', action: props.filters.action ?? '', subject: props.filters.subject ?? '',
    from: toLocalInput(props.filters.from), to: toLocalInput(props.filters.to),
});

function search(): void {
    const query: Record<string, string> = {};
    for (const key of ['actor', 'action', 'subject'] as const) {
        if (form[key] !== '') {
            query[key] = form[key].trim();
        }
    }
    for (const key of ['from', 'to'] as const) {
        if (form[key] !== '') {
            query[key] = new Date(form[key]).toISOString();
        }
    }
    router.get('/admin/audit', query, { preserveScroll: true });
}

function clear(): void {
    Object.assign(form, { actor: '', action: '', subject: '', from: '', to: '' });
    router.get('/admin/audit');
}

function when(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'long' });
}

function contextText(context: Record<string, unknown>): string {
    return Object.entries(context).map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : String(value)}`).join('; ');
}
</script>

<template>
    <AppLayout title="Audit log">
        <form class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" novalidate @submit.prevent="search">
            <div>
                <label for="audit-actor" class="block text-sm font-medium">Actor</label>
                <select id="audit-actor" v-model="form.actor" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option value="">Anyone</option>
                    <option v-for="user in props.users" :key="user.ulid" :value="user.ulid">{{ user.name }}</option>
                </select>
            </div>
            <div>
                <label for="audit-action" class="block text-sm font-medium">Action</label>
                <select id="audit-action" v-model="form.action" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option value="">Any action</option>
                    <option v-for="action in props.actions" :key="action" :value="action">{{ action }}</option>
                </select>
            </div>
            <div>
                <label for="audit-subject" class="block text-sm font-medium">Subject ID (ULID)</label>
                <input id="audit-subject" v-model="form.subject" type="text" maxlength="26" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono">
            </div>
            <div>
                <label for="audit-from" class="block text-sm font-medium">From (your local time)</label>
                <input id="audit-from" v-model="form.from" type="datetime-local" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            </div>
            <div>
                <label for="audit-to" class="block text-sm font-medium">To (your local time)</label>
                <input id="audit-to" v-model="form.to" type="datetime-local" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 font-medium text-white">Search</button>
                <button type="button" class="underline" @click="clear">Clear</button>
            </div>
        </form>

        <p v-if="props.events.length === 0" class="text-sm text-slate-700">No audit events match these filters.</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Audit events, newest first</caption>
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">When</th>
                        <th scope="col" class="py-1 pr-3">Actor</th>
                        <th scope="col" class="py-1 pr-3">Action</th>
                        <th scope="col" class="py-1 pr-3">Subject</th>
                        <th scope="col" class="py-1 pr-3">Classification</th>
                        <th scope="col" class="py-1">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="event in props.events" :key="event.ulid" class="border-b border-slate-100 align-top">
                        <td class="py-1 pr-3 whitespace-nowrap">{{ when(event.occurredAt) }}</td>
                        <td class="py-1 pr-3">{{ event.actor }}</td>
                        <td class="py-1 pr-3 font-mono">{{ event.action }}</td>
                        <td class="py-1 pr-3">
                            <template v-if="event.subjectType">{{ event.subjectType }}<span v-if="event.subjectUlid" class="block font-mono text-xs">{{ event.subjectUlid }}</span></template>
                            <template v-else>—</template>
                        </td>
                        <td class="py-1 pr-3">{{ event.classification }}</td>
                        <td class="py-1 break-all">
                            {{ contextText(event.context) }}
                            <span v-if="event.requestId" class="block font-mono text-xs text-slate-600">request {{ event.requestId }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav aria-label="Audit log pages" class="mt-4 flex gap-4 text-sm">
            <Link v-if="props.previousUrl" :href="props.previousUrl" class="underline">Newer events</Link>
            <Link v-if="props.nextUrl" :href="props.nextUrl" class="underline">Older events</Link>
        </nav>
    </AppLayout>
</template>
