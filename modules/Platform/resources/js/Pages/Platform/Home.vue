<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage<{ auth?: { user: { name: string } | null } }>();
const user = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <AppLayout title="Competitive Intelligence Command Center">
        <template v-if="user">
            <p class="mb-4 text-slate-700">Signed in as <strong>{{ user.name }}</strong>.</p>
            <nav aria-label="Account" class="flex gap-4">
                <Link href="/user/security" class="underline">Security settings</Link>
                <Link href="/logout" method="post" as="button" class="underline">Sign out</Link>
            </nav>
        </template>
        <template v-else>
            <p class="mb-4 text-slate-700">Access is by invitation only.</p>
            <Link href="/login" class="rounded bg-slate-900 px-4 py-2 font-medium text-white">Sign in</Link>
        </template>
    </AppLayout>
</template>
