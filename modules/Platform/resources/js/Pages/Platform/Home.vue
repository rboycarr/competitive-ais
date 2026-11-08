<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Tile {
    key: string;
    title: string;
    definition: string;
    href: string;
    counts?: { label: string; value: number }[];
    items?: { label: string; href: string; at: string | null }[];
}

// The dashboard (P1-46): every tile and count is already filtered by the server to what this user may see.
const props = withDefaults(defineProps<{ tiles?: Tile[] }>(), { tiles: () => [] });
const page = usePage<{ auth?: { user: { name: string } | null } }>();
const user = computed(() => page.props.auth?.user ?? null);

const date = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '');
</script>

<template>
    <AppLayout title="Competitive Intelligence Command Center">
        <template v-if="user">
            <p class="mb-4 text-slate-700">Signed in as <strong>{{ user.name }}</strong>.</p>
            <nav aria-label="Account" class="mb-6 flex gap-4">
                <Link href="/user/security" class="underline">Security settings</Link>
                <Link href="/logout" method="post" as="button" class="underline">Sign out</Link>
            </nav>

            <h2 class="mb-3 text-lg font-semibold">Overview</h2>
            <p v-if="props.tiles.length === 0" class="text-slate-600">Nothing to show yet: your role gives you no dashboard tiles.</p>
            <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Dashboard">
                <li v-for="tile in props.tiles" :key="tile.key" class="rounded border border-slate-200 p-4" :data-tile="tile.key">
                    <h3 class="font-semibold">
                        <Link :href="tile.href" class="underline">{{ tile.title }}</Link>
                    </h3>
                    <details class="mb-2 text-sm text-slate-600">
                        <summary class="cursor-pointer">What this counts</summary>
                        <p>{{ tile.definition }}</p>
                    </details>
                    <dl v-if="tile.counts && tile.counts.length > 0" class="grid grid-cols-[1fr_auto] gap-x-4 text-sm">
                        <template v-for="count in tile.counts" :key="count.label">
                            <dt class="capitalize">{{ count.label }}</dt>
                            <dd class="text-right font-medium tabular-nums">{{ count.value }}</dd>
                        </template>
                    </dl>
                    <ul v-if="tile.items && tile.items.length > 0" class="mt-2 space-y-1 text-sm">
                        <li v-for="item in tile.items" :key="item.href">
                            <Link :href="item.href" class="underline">{{ item.label }}</Link>
                            <span v-if="item.at" class="text-slate-500"> · {{ date(item.at) }}</span>
                        </li>
                    </ul>
                    <p v-if="!tile.counts?.length && !tile.items?.length" class="text-sm text-slate-500">Nothing yet.</p>
                </li>
            </ul>
        </template>
        <template v-else>
            <p class="mb-4 text-slate-700">Access is by invitation only.</p>
            <Link href="/login" class="rounded bg-slate-900 px-4 py-2 font-medium text-white">Sign in</Link>
        </template>
    </AppLayout>
</template>
