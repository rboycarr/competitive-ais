<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{ title: string }>();

type Shared = {
    auth?: { user: { name: string } | null; can?: { userView?: boolean; roleManage?: boolean; teamManage?: boolean; grantManage?: boolean; auditView?: boolean } };
    flash?: { status?: string | null };
};

const page = usePage<Shared>();
const can = computed(() => page.props.auth?.can ?? {});
const status = computed(() => page.props.flash?.status ?? null);
// Links only; every admin action is authorized on the server (P1-13).
const adminLinks = computed(() => [
    can.value.userView ? { href: '/admin/users', label: 'Users' } : null,
    can.value.roleManage ? { href: '/admin/roles', label: 'Roles' } : null,
    can.value.teamManage ? { href: '/admin/teams', label: 'Teams' } : null,
    can.value.grantManage ? { href: '/admin/access-grants', label: 'Access grants' } : null,
    can.value.auditView ? { href: '/admin/audit', label: 'Audit log' } : null,
].filter((link): link is { href: string; label: string } => link !== null));
</script>

<template>
    <Head :title="title" />
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">
        Skip to content
    </a>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center gap-6 px-4 py-3">
            <Link href="/" class="text-base font-semibold">Competitive-AIS</Link>
            <nav v-if="adminLinks.length > 0" aria-label="Administration" class="flex gap-4 text-sm">
                <Link v-for="link in adminLinks" :key="link.href" :href="link.href" class="underline">{{ link.label }}</Link>
            </nav>
        </div>
    </header>
    <main id="main" class="mx-auto max-w-5xl px-4 py-8">
        <h1 class="mb-4 text-2xl font-semibold">{{ title }}</h1>
        <p v-if="status" class="mb-4 rounded border border-green-300 bg-green-50 px-3 py-2 text-green-900" role="status">{{ status }}</p>
        <slot />
    </main>
</template>
