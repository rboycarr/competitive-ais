import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = { props: { auth: { user: null as { name: string } | null } } };

// <Head> and <Link> need a running Inertia app; the page content is what is under test.
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({
            props: { href: { type: String, required: true } },
            setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
        }),
        usePage: () => page,
    };
});

const { default: Home } = await import('./Home.vue');

describe('Platform/Home', () => {
    beforeEach(() => {
        page.props.auth.user = null;
    });

    it('offers sign-in to guests, with the title as heading and a skip link', () => {
        const wrapper = mount(Home);

        expect(wrapper.get('h1').text()).toBe('Competitive Intelligence Command Center');
        expect(wrapper.get('a[href="#main"]').text()).toBe('Skip to content');
        expect(wrapper.text()).toContain('invitation only');
        expect(wrapper.get('a[href="/login"]').text()).toBe('Sign in');
    });

    it('greets a signed-in user and links to security settings', () => {
        page.props.auth.user = { name: 'Ana Lyst' };
        const wrapper = mount(Home);

        expect(wrapper.text()).toContain('Signed in as Ana Lyst');
        expect(wrapper.find('a[href="/user/security"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/login"]').exists()).toBe(false);
    });
});
