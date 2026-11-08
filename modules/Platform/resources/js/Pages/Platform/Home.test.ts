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
        expect(wrapper.text()).toContain('no dashboard tiles');
    });

    it('shows each tile with its definition, counts and items (P1-46)', () => {
        page.props.auth.user = { name: 'Ana Lyst' };
        const wrapper = mount(Home, {
            props: {
                tiles: [
                    {
                        key: 'sources_by_status', title: 'Sources by status', definition: 'Sources you can see.', href: '/sources',
                        counts: [{ label: 'approved', value: 3 }, { label: 'proposed', value: 0 }],
                    },
                    {
                        key: 'recently_published', title: 'Recently published', definition: 'Latest published.', href: '/intelligence',
                        items: [{ label: 'Acme cuts prices', href: '/intelligence/01J', at: '2026-11-08T06:00:00Z' }],
                    },
                    { key: 'my_assignments', title: 'My assignments', definition: 'Assigned to you.', href: '/verification', items: [] },
                ],
            },
        });

        const sources = wrapper.get('[data-tile="sources_by_status"]');
        expect(sources.get('h3 a').attributes('href')).toBe('/sources');
        expect(sources.get('details').text()).toContain('Sources you can see.');
        expect(sources.findAll('dt').map((d) => d.text())).toEqual(['approved', 'proposed']);
        expect(sources.findAll('dd').map((d) => d.text())).toEqual(['3', '0']);
        expect(wrapper.get('[data-tile="recently_published"] li a').attributes('href')).toBe('/intelligence/01J');
        expect(wrapper.get('[data-tile="my_assignments"]').text()).toContain('Nothing yet.');
    });
});
