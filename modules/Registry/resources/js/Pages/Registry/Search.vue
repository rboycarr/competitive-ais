<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Hit {
    label: string;
    href: string;
    meta: string;
}

// Quick search (P1-24). A null list means the viewer may not search that kind; the server has filtered the rest.
defineProps<{ q: string; searched: boolean; limit: number; competitors: Hit[] | null; products: Hit[] | null }>();
</script>

<template>
    <AppLayout title="Search">
        <p v-if="!searched" class="text-slate-600">Type at least two characters in the search box.</p>
        <template v-else>
            <p class="mb-4 text-slate-700">Results for <strong>{{ q }}</strong> (at most {{ limit }} of each).</p>
            <section v-for="group in [{ title: 'Competitors', hits: competitors }, { title: 'Products', hits: products }]" :key="group.title" class="mb-6">
                <template v-if="group.hits !== null">
                    <h2 class="mb-2 text-lg font-semibold">{{ group.title }}</h2>
                    <p v-if="group.hits.length === 0" class="text-slate-600">No matches.</p>
                    <ul v-else class="space-y-1">
                        <li v-for="hit in group.hits" :key="hit.href">
                            <Link :href="hit.href" class="underline">{{ hit.label }}</Link>
                            <span class="text-sm text-slate-500"> · {{ hit.meta }}</span>
                        </li>
                    </ul>
                </template>
            </section>
        </template>
    </AppLayout>
</template>
