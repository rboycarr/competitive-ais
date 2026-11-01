<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">
        Skip to content
    </a>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center px-4 py-3">
            <span class="text-base font-semibold">Competitive-AIS</span>
        </div>
    </header>
    <main id="main" class="mx-auto max-w-5xl px-4 py-8">
        <h1 class="mb-4 text-2xl font-semibold">{{ title }}</h1>
        <slot />
    </main>
</template>
