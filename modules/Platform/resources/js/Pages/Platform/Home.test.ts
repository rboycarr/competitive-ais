import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Home from './Home.vue';

// <Head> needs a running Inertia app; the page content is what is under test.
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

describe('Platform/Home', () => {
    it('renders the title as the page heading and a skip link', () => {
        const wrapper = mount(Home);

        expect(wrapper.get('h1').text()).toBe('Competitive Intelligence Command Center');
        expect(wrapper.get('a[href="#main"]').text()).toBe('Skip to content');
        expect(wrapper.text()).toContain('invitation only');
    });
});
