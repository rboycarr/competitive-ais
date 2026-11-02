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
    const source = { ...row, baseUrl: 'https://news.example/feed', proposedBy: 'Ana', proposedAt: '2026-11-02T08:00:00Z', description: null, currentPolicyVersion: null };
    const review = {
        policies: [], canSubmit: false, canDecide: false, decidingOwnProposal: false, basedOnVersion: 0,
        decisions: ['approved', 'rejected', 'needs_info'], robotsDecisions: ['allowed', 'disallowed', 'not_applicable', 'not_reviewed'], robotsBound: false,
    };
    const policy = {
        version: 2, decision: 'approved', termsUrl: 'https://news.example/terms', termsSummary: 'Synthetic terms.', termsReviewedOn: '2026-11-01',
        robotsDecision: 'not_applicable', license: null, allowedCadence: null, rateBudgetPerHour: null, retentionDays: 365, containsPersonalData: false,
        personalDataBasis: null, notes: null, decidedBy: 'Dee', decidedAt: '2026-11-03T08:00:00Z',
    };

    it('links only http(s) base URLs, safely', () => {
        const link = mount(Show, { props: { source, ...review } }).get('a[target="_blank"]');
        expect(link.attributes('href')).toBe('https://news.example/feed');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');

        const unsafe = mount(Show, { props: { source: { ...source, baseUrl: 'javascript:alert(1)' }, ...review } });
        expect(unsafe.find('a[target="_blank"]').exists()).toBe(false);
    });

    it('shows every stored text as text', () => {
        const wrapper = mount(Show, { props: { source: { ...source, name: '<b>Feed</b>', host: '<i>x</i>', description: '<img src=x onerror=alert(1)>' }, ...review } });
        expect(wrapper.find('b').exists() || wrapper.find('i').exists() || wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });

    it('offers submitting only when allowed and posts it', async () => {
        expect(mount(Show, { props: { source, ...review } }).text()).not.toContain('Submit for policy review');
        const wrapper = mount(Show, { props: { source, ...review, canSubmit: true } });
        await wrapper.get('#submit-heading ~ button').trigger('click');
        expect(post).toHaveBeenCalledWith(`/sources/${source.ulid}/submit`, expect.any(Object));
    });

    it('shows the decision form only to a director who may decide, and posts the decision', async () => {
        expect(mount(Show, { props: { source, ...review } }).find('#decide-heading').exists()).toBe(false);
        const own = mount(Show, { props: { source, ...review, decidingOwnProposal: true } });
        expect(own.find('#decide-heading').exists()).toBe(false);
        expect(own.text()).toContain('another director must decide');

        const wrapper = mount(Show, { props: { source, ...review, canDecide: true, basedOnVersion: 1, robotsBound: true } });
        expect(wrapper.findAll('input[name="decision"]').map((i) => i.attributes('value'))).toEqual(['approved', 'rejected', 'needs_info']);
        expect(wrapper.text()).toContain('only be approved where robots rules allow it');
        expect(wrapper.find('#policy-basis').exists()).toBe(false);
        await wrapper.get('input[type="checkbox"]').setValue(true);
        expect(wrapper.find('#policy-basis').exists()).toBe(true);
        await wrapper.get('input[value="needs_info"]').setValue(true);
        await wrapper.get('#decide-heading ~ form').trigger('submit');
        expect(post).toHaveBeenCalledWith(`/sources/${source.ulid}/policies`, expect.any(Object));
    });

    it('lists the policy history as text, linking only http(s) terms', () => {
        const unsafe = { ...policy, version: 1, decision: 'needs_info', termsUrl: 'javascript:alert(1)', notes: '<img src=x onerror=alert(1)>' };
        const wrapper = mount(Show, { props: { source: { ...source, currentPolicyVersion: 2 }, ...review, policies: [policy, unsafe] } });
        expect(wrapper.text()).toContain('Version 2');
        expect(wrapper.text()).toContain('Version 2: Approved');
        expect(wrapper.text()).toContain('Version 1: Needs more information');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.findAll('a[target="_blank"]').map((a) => a.attributes('href'))).toEqual(['https://news.example/feed', 'https://news.example/terms']);
    });
});
