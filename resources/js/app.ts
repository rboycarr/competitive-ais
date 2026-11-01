import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';
import { resolvePage } from './pages';

createInertiaApp({
    title: (title) => (title ? `${title} · Competitive-AIS` : 'Competitive-AIS'),
    resolve: (name) => resolvePage(name, import.meta.glob<DefineComponent>('../../modules/*/resources/js/Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    // The progress bar injects a <style> element without the CSP nonce; it would be blocked (P1-08).
    progress: false,
});
