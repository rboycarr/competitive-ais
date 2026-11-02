import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const get = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => reactive({ ...initial, errors: {}, processing: false, post }),
    };
});

const { default: Index } = await import('./Index.vue');
const { default: Show } = await import('./Show.vue');

const row = { ulid: '01JSOURCE00000000000000000', name: '<b>Feed</b>', type: 'rss', host: 'news.example', method: 'manual', status: 'proposed', reliability: 'F', owner: 'Ana', classification: 'internal' };
const options = { statuses: ['proposed', 'approved'], types: ['website', 'rss'], reliabilities: ['A', 'F'], owners: [{ ulid: '01JUSER0000000000000000000', name: 'Ana' }], classifications: ['public', 'internal'] };

describe('Sources/Index', () => {
    it('shows names as text and offers only manual collection while it is the only method', async () => {
        const wrapper = mount(Index, { props: { sources: [row], filters: {}, canPropose: true, methods: ['manual'], ...options } });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('RSS or Atom feed');
        expect(wrapper.findAll('#source-method option').map((o) => o.attributes('value'))).toEqual(['manual']);
        expect(wrapper.text()).toContain('Automated collection is switched off');

        await wrapper.get('#source-name').setValue('Trade news');
        await wrapper.get('#source-type').setValue('website');
        await wrapper.get('section form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/sources', expect.any(Object));
    });

    it('filters by status and type, and hides the form without source.propose', async () => {
        const wrapper = mount(Index, { props: { sources: [], filters: {}, canPropose: false, methods: ['manual'], ...options, owners: [], classifications: [] } });
        expect(wrapper.find('#source-name').exists()).toBe(false);
        expect(wrapper.text()).toContain('No sources match.');

        await wrapper.get('#filter-type').setValue('rss');
        await wrapper.get('form').trigger('submit');
        expect(get).toHaveBeenCalledWith('/sources', { type: 'rss' }, expect.any(Object));
    });
});

describe('Sources/Show', () => {
    const source = { ...row, baseUrl: 'https://news.example/feed', proposedBy: 'Ana', proposedAt: '2026-11-02T08:00:00Z', description: null };

    it('links only http(s) base URLs, safely', () => {
        const link = mount(Show, { props: { source } }).get('a[target="_blank"]');
        expect(link.attributes('href')).toBe('https://news.example/feed');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');

        const unsafe = mount(Show, { props: { source: { ...source, baseUrl: 'javascript:alert(1)' } } });
        expect(unsafe.find('a[target="_blank"]').exists()).toBe(false);
    });

    it('shows every stored text as text', () => {
        const wrapper = mount(Show, { props: { source: { ...source, name: '<b>Feed</b>', host: '<i>x</i>', description: '<img src=x onerror=alert(1)>' } } });
        expect(wrapper.find('b').exists() || wrapper.find('i').exists() || wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });
});
