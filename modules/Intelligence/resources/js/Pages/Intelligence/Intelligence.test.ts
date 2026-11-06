import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { MarkdownNode } from '@/Components/MarkdownView';

const post = vi.fn();
const put = vi.fn();
const get = vi.fn();
const routerPost = vi.fn();
const reload = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get, post: routerPost, reload },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial, errors: {}, processing: false, post, put,
                transform: () => form, reset: vi.fn(), clearErrors: vi.fn(),
            });

            return form;
        },
    };
});

const { default: Index } = await import('./Index.vue');
const { default: Show } = await import('./Show.vue');

const options = {
    types: ['news', 'product_change'], classifications: ['public', 'internal'],
    competitorOptions: [{ ulid: '01JCOMP0000000000000000000', name: 'Acme' }], productOptions: [], marketOptions: [],
};
const item = {
    ulid: '01JITEM0000000000000000000', title: '<b>Launch</b>', type: 'product_change', classification: 'internal', highImpact: true,
    status: 'draft', version: 3, author: 'Ana', owner: 'Ana', observedAt: null, publishedAt: null, collectedAt: null, createdAt: null, updatedAt: null,
    summary: <MarkdownNode[]>[
        { tag: 'h1', attrs: {}, children: [{ text: 'Heading' }] },
        { tag: 'p', attrs: {}, children: [
            { tag: 'a', attrs: { href: 'https://news.example/a', onclick: 'alert(1)' }, children: [{ text: 'site' }] },
            { tag: 'a', attrs: { href: 'javascript:alert(1)' }, children: [{ text: 'bad' }] },
            { tag: 'script', attrs: {}, children: [{ text: 'alert(2)' }] },
            { text: '<img src=x onerror=alert(3)>' },
        ] },
    ],
};
const claims = [
    {
        ulid: '01JCLAIM000000000000000001', statement: 'Listed on its site', type: 'observed_fact', reliability: 'B', credibility: 2, status: 'active', hiddenEvidence: 1,
        evidence: [
            { ulid: '01JEVID0000000000000000001', title: 'Price list', status: 'available', capturedAt: '2026-11-01T10:00:00Z', relation: 'supports', locator: 'p. 4', excerpt: '<script>alert(1)</script> from $9', source: { visible: true as const, name: 'Filings', reliability: 'B' } },
            { ulid: '01JEVID0000000000000000002', title: 'Screenshot', status: 'withdrawn', capturedAt: '2026-11-01T10:00:00Z', relation: 'contradicts', locator: null, excerpt: null, source: { visible: false as const } },
        ],
    },
    { ulid: '01JCLAIM000000000000000002', statement: 'Old guess', type: 'rumor', reliability: 'F', credibility: 6, status: 'withdrawn', hiddenEvidence: 0, evidence: [] },
];
const showProps = {
    item, claims, subjects: { competitors: [{ ulid: '01JCOMP0000000000000000000', name: 'Acme' }], products: [], markets: [], hidden: 1 },
    claimTypes: ['observed_fact', 'rumor'], reliabilities: ['A', 'B', 'F'], credibilities: [1, 2, 6],
    relations: ['supports', 'contradicts', 'context'], evidenceOptions: [{ ulid: '01JEVID0000000000000000003', title: 'Annual report', capturedAt: '2026-10-01T00:00:00Z' }], evidenceSearch: null,
};

describe('Intelligence/Index', () => {
    it('lists items as text and posts a new draft', async () => {
        const row = { ulid: item.ulid, title: '<b>Launch</b>', type: 'news', status: 'draft', classification: 'internal', highImpact: true, competitors: ['Acme'], updatedAt: null };
        const wrapper = mount(Index, { props: { items: [row], nextPage: null, filters: {}, statuses: ['draft'], filterCompetitors: [], canCreate: true, ...options } });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>Launch</b>');
        expect(wrapper.text()).toContain('High impact');

        await wrapper.get('#new-item-title').setValue('New');
        await wrapper.get('section form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/intelligence', expect.any(Object));
    });

    it('hides the form without intelligence.create', () => {
        const wrapper = mount(Index, { props: { items: [], nextPage: null, filters: {}, statuses: [], filterCompetitors: [], canCreate: false, ...options } });
        expect(wrapper.text()).toContain('No intelligence matches.');
        expect(wrapper.find('#new-item-title').exists()).toBe(false);
    });
});

describe('Intelligence/Show', () => {
    it('renders the summary from allowlisted nodes only, and the rating badge with its meaning', () => {
        const wrapper = mount(Show, { props: { ...showProps, canEdit: false, form: null } });
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('.markdown h1').exists()).toBe(false); // headings sit below the page's own
        expect(wrapper.find('.markdown h3').text()).toBe('Heading');
        const links = wrapper.findAll('.markdown a');
        expect(links).toHaveLength(1);
        expect(links[0]?.attributes()).toEqual({ href: 'https://news.example/a', rel: 'noopener noreferrer nofollow', class: 'underline' });
        expect(wrapper.text()).toContain('bad');
        expect(wrapper.text()).toContain('<img src=x onerror=alert(3)>');

        expect(wrapper.text()).toContain('B2');
        expect(wrapper.text()).toContain('Usually reliable; probably true');
        expect(wrapper.text()).toContain('1 subject hidden (insufficient access)');
        expect(wrapper.text()).toContain('1 withdrawn claim (kept on record)');
        expect(wrapper.find('#claim-statement').exists()).toBe(false);
    });

    it('lets the author add, withdraw and move claims at the current version', async () => {
        const wrapper = mount(Show, { props: { ...showProps, canEdit: true, form: null } });
        // No rating is preselected (ADR-0010).
        expect((wrapper.get('#claim-reliability').element as HTMLSelectElement).value).toBe('');
        expect((wrapper.get('#claim-credibility').element as HTMLSelectElement).value).toBe('');

        await wrapper.get('#claim-statement').setValue('A new claim');
        await wrapper.get('section[aria-labelledby="claim-form-heading"] form').trigger('submit');
        expect(post).toHaveBeenCalledWith(`/intelligence/${item.ulid}/claims`, expect.any(Object));

        const buttons = wrapper.findAll('ol button');
        await buttons.find((b) => b.text() === 'Withdraw')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/claims/${claims[0]?.ulid}/withdraw`, { version: 3 }, expect.any(Object));
        expect(buttons.find((b) => b.text() === 'Move up')?.attributes('disabled')).toBeDefined();
    });

    it('shows citations as text, counts hidden ones, and cites and stops citing at the current version', async () => {
        const reader = mount(Show, { props: { ...showProps, canEdit: false, form: null } });
        expect(reader.find('li script').exists()).toBe(false);
        expect(reader.text()).toContain('<script>alert(1)</script> from $9');
        expect(reader.text()).toContain('Source: Filings · default reliability B (Usually reliable)');
        expect(reader.text()).toContain('Source hidden (insufficient access)');
        expect(reader.text()).toContain('(withdrawn)');
        expect(reader.text()).toContain('1 more citation hidden (insufficient access)');
        expect(reader.findAll('button').some((b) => b.text() === 'Cite evidence' || b.text() === 'Stop citing')).toBe(false);

        const editor = mount(Show, { props: { ...showProps, canEdit: true, form: null } });
        await editor.findAll('button').find((b) => b.text() === 'Stop citing')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/claims/${claims[0]?.ulid}/evidence/remove`,
            { evidence: '01JEVID0000000000000000001', relation: 'supports', version: 3 }, expect.any(Object));

        await editor.findAll('button').find((b) => b.text() === 'Cite evidence')?.trigger('click');
        const relation = editor.get(`#cite-relation-${claims[0]?.ulid}`);
        expect((relation.element as HTMLSelectElement).value).toBe(''); // never defaulted
        await editor.get(`#cite-search-${claims[0]?.ulid}`).setValue('annual');
        await editor.findAll('button').find((b) => b.text() === 'Search')?.trigger('click');
        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['evidenceOptions', 'evidenceSearch'], data: { evidence_search: 'annual' } }));
        await editor.get(`#cite-evidence-${claims[0]?.ulid}`).setValue('01JEVID0000000000000000003');
        await relation.setValue('supports');
        await editor.get(`#cite-relation-${claims[0]?.ulid}`).element.closest('form')?.dispatchEvent(new Event('submit'));
        expect(post).toHaveBeenCalledWith(`/intelligence/${item.ulid}/claims/${claims[0]?.ulid}/evidence`, expect.any(Object));
    });
});
