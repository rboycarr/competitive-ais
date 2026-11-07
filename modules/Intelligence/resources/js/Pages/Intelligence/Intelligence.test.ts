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
const { default: Queue } = await import('./Queue.vue');
const { default: Provenance } = await import('./Provenance.vue');
const { ageLabel } = await import('./labels');

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
type Linked = { ulid: string; title: string; status: string } | null;
const showProps = {
    item, claims, subjects: { competitors: [{ ulid: '01JCOMP0000000000000000000', name: 'Acme' }], products: [], markets: [], hidden: 1 },
    claimTypes: ['observed_fact', 'rumor'], reliabilities: ['A', 'B', 'F'], credibilities: [1, 2, 6],
    submission: null as { problems: string[] } | null,
    review: {
        reviewer: null as string | null, submittedAt: null as string | null, verifiedAt: null as string | null,
        history: [] as { ulid: string; decision: string; by: string | null; assignee: string | null; rationale: string | null; version: number; decidedAt: string }[],
        canAssign: false, reviewers: [] as { ulid: string; name: string }[], canStart: false, canDecide: false,
        decisions: ['approve', 'request_changes', 'reject'], approvalProblems: [] as string[],
    },
    publication: {
        releasedAt: null as string | null, supersedes: null as Linked, supersededBy: null as Linked,
        retraction: null as { reason: string; by: string | null; at: string; replacement: Linked } | null,
        canPublish: false, publishProblems: [] as string[], canRetract: false, replacementOptions: [] as { ulid: string; title: string }[], canReplace: false,
    },
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

    it('lists what blocks submission, and submits at the current version once nothing does', async () => {
        const blocked = mount(Show, { props: { ...showProps, canEdit: true, form: null, submission: { problems: ['Claim 1 cites no available evidence that supports it.'] } } });
        expect(blocked.text()).toContain('Claim 1 cites no available evidence that supports it.');
        expect(blocked.findAll('button').some((b) => b.text() === 'Submit for review')).toBe(false);

        const ready = mount(Show, { props: { ...showProps, canEdit: true, form: null, submission: { problems: [] } } });
        await ready.findAll('button').find((b) => b.text() === 'Submit for review')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/submit`, { version: 3 }, expect.any(Object));

        const reader = mount(Show, { props: { ...showProps, canEdit: false, form: null } });
        expect(reader.find('#submit-heading').exists()).toBe(false);
    });
});

describe('Intelligence verification (P1-43)', () => {
    it('lets a director assign, and asks for a reason to reassign', async () => {
        const unassigned = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, canAssign: true, reviewers: [{ ulid: '01JUSER0000000000000000001', name: 'Rui' }] } } });
        expect(unassigned.get('label[for="assign-reviewer"]').text()).toBe('Assign a reviewer');
        expect(unassigned.find('#assign-rationale').exists()).toBe(false);
        await unassigned.get('#assign-reviewer').setValue('01JUSER0000000000000000001');
        await unassigned.get('#assign-reviewer').element.closest('form')?.dispatchEvent(new Event('submit'));
        expect(post).toHaveBeenCalledWith(`/intelligence/${item.ulid}/assign`, expect.any(Object));

        const assigned = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, reviewer: 'Rui', canAssign: true } } });
        expect(assigned.get('label[for="assign-reviewer"]').text()).toBe('Reassign the review');
        expect(assigned.get('#assign-rationale').attributes('required')).toBeDefined();
        expect(assigned.text()).toContain('Nobody else who reviews intelligence can see this item.');
    });

    it('lets the assigned reviewer start, then decide with no decision preselected', async () => {
        const waiting = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, reviewer: 'Rui', canStart: true } } });
        await waiting.findAll('button').find((b) => b.text() === 'Start review')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/review`, {}, expect.any(Object));

        const reviewing = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, reviewer: 'Rui', canDecide: true } } });
        const radios = reviewing.findAll('input[type="radio"]');
        expect(radios.map((r) => (r.element as HTMLInputElement).checked)).toEqual([false, false, false]);
        const record = reviewing.findAll('button').find((b) => b.text() === 'Record decision');
        expect(record?.attributes('disabled')).toBeDefined();
        await radios[2]?.setValue(true);
        expect(reviewing.get('#decision-rationale').attributes('required')).toBeDefined();
        await reviewing.get('#decision-rationale').element.closest('form')?.dispatchEvent(new Event('submit'));
        expect(post).toHaveBeenCalledWith(`/intelligence/${item.ulid}/decision`, expect.any(Object));

        const blocked = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, canDecide: true, approvalProblems: ['Claim 1 cites no available evidence that you can see and that supports it.'] } } });
        expect(blocked.text()).toContain('Claim 1 cites no available evidence that you can see and that supports it.');
        expect(blocked.findAll('input[type="radio"]')[0]?.attributes('disabled')).toBeDefined();
    });

    it('shows the review history as text', () => {
        const wrapper = mount(Show, { props: { ...showProps, canEdit: false, form: null, review: { ...showProps.review, reviewer: 'Rui', history: [
            { ulid: '01JREV00000000000000000001', decision: 'reassign', by: 'Dee', assignee: 'Rui', rationale: '<b>On leave</b>', version: 3, decidedAt: '2026-11-01T10:00:00Z' },
        ] } } });
        expect(wrapper.text()).toContain('Reassigned to Rui by Dee · version 3');
        expect(wrapper.find('#review-heading ~ ol b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>On leave</b>');
        expect(mount(Show, { props: { ...showProps, canEdit: false, form: null } }).find('#review-heading').exists()).toBe(false);
    });

    it('lists the queue with its age and filters it', async () => {
        const row = { ulid: item.ulid, title: '<i>Launch</i>', status: 'submitted', classification: 'internal', highImpact: false, competitors: ['Acme'], reviewer: null, mine: false, submittedAt: '2026-11-01T10:00:00Z' };
        const wrapper = mount(Queue, { props: { items: [row, { ...row, ulid: '01JITEM0000000000000000002', reviewer: 'Rui', mine: true }], nextPage: null, filters: {}, statuses: ['submitted', 'in_review'], filterCompetitors: [] } });
        expect(wrapper.find('td i').exists()).toBe(false);
        expect(wrapper.text()).toContain('<i>Launch</i>');
        expect(wrapper.text()).toContain('Not assigned');
        expect(wrapper.text()).toContain('You');
        await wrapper.get('#filter-assigned').setValue('mine');
        await wrapper.get('form').trigger('submit');
        expect(get).toHaveBeenCalledWith('/verification', { assigned: 'mine' }, expect.any(Object));
    });

    it('labels an age in minutes, hours or days', () => {
        const now = new Date('2026-11-06T12:00:00Z');
        expect(ageLabel(null, now)).toBe('—');
        expect(ageLabel('2026-11-06T11:59:00Z', now)).toBe('1 minute');
        expect(ageLabel('2026-11-06T09:00:00Z', now)).toBe('3 hours');
        expect(ageLabel('2026-11-04T11:00:00Z', now)).toBe('2 days');
        expect(ageLabel('2026-11-07T00:00:00Z', now)).toBe('0 minutes');
    });
});

describe('Intelligence publication (P1-44)', () => {
    const reader = (publication: Partial<typeof showProps.publication>, status = 'published') => mount(Show, {
        props: { ...showProps, item: { ...item, status }, canEdit: false, form: null, publication: { ...showProps.publication, ...publication } },
    });

    it('publishes at the current version once nothing blocks it', async () => {
        const blocked = reader({ canPublish: true, publishProblems: ['It changed after it was approved. It must be reviewed again.'] }, 'approved');
        expect(blocked.text()).toContain('It changed after it was approved. It must be reviewed again.');
        expect(blocked.findAll('button').some((b) => b.text() === 'Publish')).toBe(false);

        const ready = reader({ canPublish: true }, 'approved');
        await ready.findAll('button').find((b) => b.text() === 'Publish')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/publish`, { version: 3 }, expect.any(Object));
        expect(reader({}, 'approved').find('#publication-heading').exists()).toBe(false);
    });

    it('retracts with a reason and an optional replacement, and drafts a replacement', async () => {
        const wrapper = reader({ canRetract: true, canReplace: true, replacementOptions: [{ ulid: '01JITEM0000000000000000009', title: '<b>Fixed</b>' }] });
        expect(wrapper.get('#retract-reason').attributes('required')).toBeDefined();
        expect((wrapper.get('#retract-replacement').element as HTMLSelectElement).value).toBe('');
        expect(wrapper.find('#retract-replacement b').exists()).toBe(false);
        await wrapper.get('#retract-reason').setValue('Wrong product');
        await wrapper.get('#retract-reason').element.closest('form')?.dispatchEvent(new Event('submit'));
        expect(post).toHaveBeenCalledWith(`/intelligence/${item.ulid}/retract`, expect.any(Object));
        await wrapper.findAll('button').find((b) => b.text() === 'Draft a replacement')?.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/intelligence/${item.ulid}/replacement`);
    });

    it('marks retracted and superseded items, as text', () => {
        const retracted = reader({ retraction: { reason: '<i>Wrong</i> product', by: 'Dee', at: '2026-11-01T10:00:00Z', replacement: { ulid: '01JITEM0000000000000000009', title: 'Fixed', status: 'published' } } }, 'retracted');
        expect(retracted.text()).toContain('Retracted');
        expect(retracted.text()).toContain('<i>Wrong</i> product');
        expect(retracted.find('[role="note"] i').exists()).toBe(false);
        expect(retracted.get('[role="note"] a').attributes('href')).toBe('/intelligence/01JITEM0000000000000000009');

        expect(reader({ supersededBy: { ulid: '01JITEM0000000000000000009', title: 'Fixed', status: 'published' } }, 'superseded').text()).toContain('Superseded by Fixed.');
        expect(reader({}, 'superseded').text()).toContain('Superseded by an item you cannot see.');
        expect(reader({ supersedes: { ulid: '01JITEM0000000000000000008', title: 'Old', status: 'published' } }, 'draft').text()).toContain('Will replace, once published, Old.');
    });
});

describe('Intelligence/Provenance (P1-45)', () => {
    const asset = {
        ulid: '01JEVID0000000000000000001', title: '<b>Price list</b>', status: 'available', classification: 'internal', capturedAt: '2026-11-01T10:00:00Z',
        uploadedBy: 'Ana', sha256: 'b'.repeat(64), sizeBytes: 10, mime: 'application/pdf', integrity: 'ok',
        source: { visible: false as const }, custody: [{ event: 'scan_clean', actor: null, occurredAt: '2026-11-01T10:01:00Z' }],
    };
    const props = {
        item: { ulid: item.ulid, title: 'Launch', status: 'published', classification: 'internal', version: 3, author: 'Ana', owner: 'Ana', submittedAt: null, verifiedAt: null, releasedAt: null },
        content: { hash: 'c'.repeat(64), approvedHash: 'c'.repeat(64), matchesApproval: true as boolean | null },
        claims: [{ ulid: '01JCLAIM000000000000000001', statement: '<i>Sold</i>', type: 'observed_fact', reliability: 'B', credibility: 2, status: 'active',
            evidence: [{ relation: 'supports', locator: 'p. 4', excerpt: '<script>x</script>', asset }], hiddenEvidence: 1 }],
        reviews: [{ ulid: '01JREV00000000000000000001', decision: 'approve', by: 'Rui', assignee: null, rationale: null, version: 3, contentHash: 'c'.repeat(64), decidedAt: '2026-11-02T10:00:00Z' }],
        retraction: null, supersedes: null, supersededBy: null,
        audit: null as { events: { ulid: string; action: string; actor: string; occurredAt: string; context: Record<string, unknown> }[]; more: boolean } | null,
    };

    it('traces claims to evidence, hashes, sources and custody as text, counting hidden evidence', () => {
        const wrapper = mount(Provenance, { props });
        expect(wrapper.find('i, b, script').exists()).toBe(false);
        expect(wrapper.text()).toContain('<i>Sold</i>');
        expect(wrapper.text()).toContain('<b>Price list</b>');
        expect(wrapper.text()).toContain('b'.repeat(64));
        expect(wrapper.text()).toContain('Recorded, but not visible at your access');
        expect(wrapper.text()).toContain('1 evidence item hidden (insufficient access)');
        expect(wrapper.text()).toContain('Yes: the content is what the reviewer approved');
        expect(wrapper.text()).toContain('(as now)');
        expect(wrapper.find('#audit-heading').exists()).toBe(false);
    });

    it('flags content that changed after approval, and shows the audit trail to auditors', () => {
        const wrapper = mount(Provenance, { props: { ...props, content: { ...props.content, matchesApproval: false },
            audit: { events: [{ ulid: '01JAUD00000000000000000001', action: 'intelligence.published', actor: 'Dee', occurredAt: '2026-11-02T11:00:00Z', context: { version: 3 } }], more: true } } });
        expect(wrapper.text()).toContain('No: the content changed after it was approved');
        expect(wrapper.text()).toContain('intelligence.published by Dee');
        expect(wrapper.get('#audit-heading ~ p a').attributes('href')).toBe(`/admin/audit?subject=${item.ulid}`);
    });
});
