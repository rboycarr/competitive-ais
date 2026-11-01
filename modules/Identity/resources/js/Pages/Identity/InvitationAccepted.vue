<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <AppLayout title="Account created">
        <p class="mb-4 text-slate-700">Your account is ready.</p>
        <Link href="/login" class="rounded bg-slate-900 px-4 py-2 font-medium text-white">Sign in</Link>
    </AppLayout>
</template>
