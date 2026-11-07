import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const get = vi.fn();
const routerPost = vi.fn();
let transformed: Record<string, unknown> | null = null;
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get, post: routerPost },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({ ...initial, errors: {}, processing: false, progress: null as null | { percentage: number },
                transform(fn: (data: Record<string, unknown>) => Record<string, unknown>) {
                    transformed = fn({ ...initial, ...Object.fromEntries(Object.entries(form).filter(([key]) => key in initial)) });

                    return form;
                },
                post });

            return form;
        },
    };
});

const { default: Index } = await import('./Index.vue');
const { default: Show } = await import('./Show.vue');
const { sizeLabel } = await import('./labels');

const row = { ulid: '01JASSET000000000000000000', title: '<b>Shelf</b>', status: 'quarantined', capturedAt: '2026-11-02T08:00:00Z', uploadedBy: 'Ana', classification: 'internal' };
const options = { filters: {}, statuses: ['quarantined', 'available', 'rejected', 'withdrawn'], maxBytes: 50 * 1024 * 1024, competitors: [], markets: [], sources: [] };

describe('Evidence/Index', () => {
    it('lists titles as text with readable statuses, and filters by status', async () => {
        const wrapper = mount(Index, { props: { assets: [row], canUpload: false, classifications: [], ...options } });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>Shelf</b>');
        expect(wrapper.text()).toContain('In quarantine');
        expect(wrapper.find('#evidence-file').exists()).toBe(false);

        await wrapper.get('#filter-status').setValue('rejected');
        await wrapper.get('form').trigger('submit');
        expect(get).toHaveBeenCalledWith('/evidence', { status: 'rejected' }, expect.any(Object));
    });

    it('uploads as form data with the capture time in UTC, and states the limits', async () => {
        const wrapper = mount(Index, { props: { assets: [], canUpload: true, classifications: ['public', 'internal'], ...options } });
        expect(wrapper.text()).toContain('up to 50 MB');
        expect(wrapper.text()).toContain('only you and directors see it');
        expect(wrapper.get('button[type="submit"]:not(.px-4.py-1\\.5)').attributes('disabled')).toBeDefined();

        const file = new File(['%PDF-1.4'], 'report.pdf', { type: 'application/pdf' });
        const input = wrapper.get('#evidence-file');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        await wrapper.get('#evidence-title').setValue('Shelf prices');
        await wrapper.get('#evidence-captured').setValue('2026-11-02T08:30');
        await wrapper.get('section form').trigger('submit');

        expect(post).toHaveBeenCalledWith('/evidence', expect.objectContaining({ forceFormData: true }));
        expect(transformed?.file).toBe(file);
        expect(transformed?.captured_at).toBe(new Date('2026-11-02T08:30').toISOString());
        expect(transformed?.classification).toBe('internal');
        expect([transformed?.competitors, transformed?.markets, transformed?.source]).toEqual([[], [], '']);
    });

    it('offers the competitors and markets it is given, sends the chosen ones, and warns that scopes narrow who sees it', async () => {
        const scoped = { ...options, competitors: [{ value: '01JCOMP0000000000000000000', label: '<b>Nord</b>' }], markets: [{ value: '01JMARK0000000000000000000', label: 'Philippines (PH)' }] };
        const wrapper = mount(Index, { props: { assets: [], canUpload: true, classifications: ['internal'], ...scoped } });
        expect(wrapper.get('#evidence-competitors').text()).toBe('<b>Nord</b>');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('Only people with access to every competitor and market you choose will see this evidence.');

        const file = new File(['%PDF-1.4'], 'report.pdf', { type: 'application/pdf' });
        Object.defineProperty(wrapper.get('#evidence-file').element, 'files', { value: [file] });
        await wrapper.get('#evidence-file').trigger('change');
        await wrapper.get('#evidence-competitors').setValue(['01JCOMP0000000000000000000']);
        await wrapper.get('#evidence-markets').setValue(['01JMARK0000000000000000000']);
        await wrapper.get('section form').trigger('submit');
        expect([transformed?.competitors, transformed?.markets]).toEqual([['01JCOMP0000000000000000000'], ['01JMARK0000000000000000000']]);
    });

    it('offers the approved sources it is given as text and sends the chosen one', async () => {
        const sourced = { ...options, sources: [{ value: '01JSRC00000000000000000000', label: '<b>Trade news</b>' }] };
        const wrapper = mount(Index, { props: { assets: [], canUpload: true, classifications: ['internal'], ...sourced } });
        expect(wrapper.get('#evidence-source').text()).toBe('Unregistered source<b>Trade news</b>');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain("The source's policy in force is recorded with the evidence.");

        const file = new File(['%PDF-1.4'], 'report.pdf', { type: 'application/pdf' });
        Object.defineProperty(wrapper.get('#evidence-file').element, 'files', { value: [file] });
        await wrapper.get('#evidence-file').trigger('change');
        await wrapper.get('#evidence-source').setValue('01JSRC00000000000000000000');
        await wrapper.get('section form').trigger('submit');
        expect(transformed?.source).toBe('01JSRC00000000000000000000');
    });
});

describe('Evidence/Show', () => {
    const asset = {
        ulid: row.ulid, title: 'Shelf', description: '<img src=x onerror=alert(1)>', status: 'quarantined', scanStatus: 'pending', originalFilename: '<i>a</i>.pdf',
        sizeBytes: 2048, sourceUrl: 'https://shop.example/item?id=7', capturedAt: row.capturedAt, uploadedBy: 'Ana', uploadedAt: row.capturedAt, classification: 'internal', legalHold: false,
        competitors: ['<b>Nord</b>'], markets: [], source: null,
    };

    it('explains quarantine, shows stored text as text, and links only http(s) sources', () => {
        const wrapper = mount(Show, { props: { asset, canDownload: false, canHold: false, custody: [{ event: 'uploaded', actor: '<u>Ana</u>', occurredAt: row.capturedAt, reason: null }] } });
        expect(wrapper.get('[role="status"]').text()).toContain('nobody can open it');
        expect(wrapper.find('img').exists() || wrapper.find('i').exists() || wrapper.find('u').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.text()).toContain('<i>a</i>.pdf (2.0 KB)');
        expect(wrapper.text()).toContain('Uploaded by <u>Ana</u>');
        expect(wrapper.text()).toContain('Competitors<b>Nord</b>');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.get('a[target="_blank"]').attributes('rel')).toBe('noopener noreferrer nofollow');

        const unsafe = mount(Show, { props: { asset: { ...asset, status: 'available', scanStatus: 'clean', sourceUrl: 'javascript:alert(1)' }, canDownload: false, canHold: false, custody: [] } });
        expect(unsafe.find('a[target="_blank"]').exists()).toBe(false);
        expect(unsafe.find('[role="status"]').exists()).toBe(false);
        expect(unsafe.find('button').exists()).toBe(false);
    });

    it('shows the source and the policy version that authorized the capture, or a placeholder when it is hidden', () => {
        const none = mount(Show, { props: { asset, canDownload: false, canHold: false, custody: [] } });
        expect(none.text()).toContain('SourceUnregistered source'); // PO decision O12

        const source = { visible: true as const, ulid: '01JSRC00000000000000000000', name: '<b>Trade news</b>', status: 'suspended', policyVersion: 2, policyDecidedAt: '2026-11-01T09:00:00Z' };
        const shown = mount(Show, { props: { asset: { ...asset, source }, canDownload: false, canHold: false, custody: [] } });
        expect(shown.get('a[href="/sources/01JSRC00000000000000000000"]').text()).toBe('<b>Trade news</b>');
        expect(shown.find('b').exists()).toBe(false);
        expect(shown.text()).toContain('captured under policy version 2');
        expect(shown.text()).toContain('(the source is now suspended)');

        const hidden = mount(Show, { props: { asset: { ...asset, source: { visible: false as const } }, canDownload: false, canHold: false, custody: [] } });
        expect(hidden.text()).toContain('Recorded, but not visible at your access');
        expect(hidden.find('a[href^="/sources/"]').exists()).toBe(false);
    });

    it('offers the download only when allowed, posts it, and shows a refusal as text', async () => {
        const wrapper = mount(Show, { props: { asset: { ...asset, status: 'available', scanStatus: 'clean' }, canDownload: true, canHold: false, custody: [] } });
        await wrapper.get('button').trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/evidence/${row.ulid}/download`, {}, expect.any(Object));

        const options = routerPost.mock.calls[0]?.[2] as { onError: (errors: Record<string, string>) => void };
        options.onError({ download: '<b>Only available evidence can be downloaded.</b>' });
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[role="alert"]').text()).toBe('<b>Only available evidence can be downloaded.</b>');
        expect(wrapper.find('b').exists()).toBe(false);
    });

    it('lets a holder place or release a legal hold with a reason, and shows reasons as text', async () => {
        const available = { ...asset, status: 'available', scanStatus: 'clean' };
        const reader = mount(Show, { props: { asset: { ...available, legalHold: true }, canDownload: false, canHold: false, custody: [
            { event: 'hold_set', actor: 'Dana', occurredAt: row.capturedAt, reason: '<b>Litigation</b> notice 12' },
        ] } });
        expect(reader.get('[role="note"]').text()).toContain('On legal hold');
        expect(reader.find('#hold-reason').exists()).toBe(false);
        expect(reader.text()).toContain('Reason: <b>Litigation</b> notice 12');
        expect(reader.find('b').exists()).toBe(false);

        const holder = mount(Show, { props: { asset: available, canDownload: false, canHold: true, custody: [] } });
        expect(holder.find('[role="note"]').exists()).toBe(false);
        expect(holder.get('label[for="hold-reason"]').text()).toBe('Reason for placing a legal hold');
        expect(holder.get('#hold-reason').attributes('required')).toBeDefined();
        await holder.get('#hold-reason').setValue('Litigation notice 12');
        await holder.get('form').trigger('submit');
        expect(post).toHaveBeenLastCalledWith(`/evidence/${row.ulid}/hold`, expect.any(Object));

        const release = mount(Show, { props: { asset: { ...available, legalHold: true }, canDownload: false, canHold: true, custody: [] } });
        expect(release.get('button[type="submit"]').text()).toBe('Release legal hold');
    });

    it('formats sizes', () => {
        expect([sizeLabel(512), sizeLabel(2048), sizeLabel(50 * 1024 * 1024)]).toEqual(['512 B', '2.0 KB', '50.0 MB']);
    });
});

describe('Evidence/Show citations (P1-45)', () => {
    const asset = {
        ulid: row.ulid, title: 'Shelf', description: null, status: 'available', scanStatus: 'clean', originalFilename: 'a.pdf', sizeBytes: 10,
        sourceUrl: null, capturedAt: row.capturedAt, uploadedBy: 'Ana', uploadedAt: row.capturedAt, classification: 'internal', legalHold: false,
        competitors: [], markets: [], source: null, sha256: 'a'.repeat(64),
    };

    it('shows the content hash and the intelligence citing it, counting hidden items', () => {
        const wrapper = mount(Show, { props: { asset, canDownload: false, canHold: false, custody: [], citations: {
            items: [{ ulid: '01JITEM0000000000000000001', title: '<b>Launch</b>', status: 'in_review', relations: ['contradicts', 'supports'] }], hidden: 2,
        } } });
        expect(wrapper.text()).toContain('a'.repeat(64));
        expect(wrapper.find('#citations-heading ~ ul b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>Launch</b> (in review; contradicts, supports)');
        expect(wrapper.get('#citations-heading ~ ul a').attributes('href')).toBe('/intelligence/01JITEM0000000000000000001');
        expect(wrapper.text()).toContain('2 more items hidden (insufficient access)');

        expect(mount(Show, { props: { asset, canDownload: false, canHold: false, custody: [], citations: { items: [], hidden: 0 } } }).text()).toContain('No intelligence cites it.');
        expect(mount(Show, { props: { asset: { ...asset, sha256: null }, canDownload: false, canHold: false, custody: [], citations: null } }).find('#citations-heading').exists()).toBe(false);
    });
});

describe('Evidence/Show withdrawal and integrity (P1-48)', () => {
    const asset = {
        ulid: row.ulid, title: 'Shelf', description: null, status: 'available', scanStatus: 'clean', originalFilename: 'a.pdf', sizeBytes: 10,
        sourceUrl: null, capturedAt: row.capturedAt, uploadedBy: 'Ana', uploadedAt: row.capturedAt, classification: 'internal', legalHold: false,
        competitors: [], markets: [], source: null, sha256: 'a'.repeat(64), integrity: 'ok', withdrawnAt: null,
    };

    it('lets a director withdraw with a reason, only after confirming', async () => {
        const reader = mount(Show, { props: { asset, canDownload: false, canHold: false, canWithdraw: false, custody: [] } });
        expect(reader.find('#withdraw-reason').exists()).toBe(false);

        const director = mount(Show, { props: { asset, canDownload: false, canHold: false, canWithdraw: true, custody: [] } });
        expect(director.get('#withdraw-reason').attributes('required')).toBeDefined();
        await director.get('#withdraw-reason').setValue('Fabricated screenshot');
        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        post.mockClear();
        await director.get('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
        await director.get('form').trigger('submit');
        expect(post).toHaveBeenLastCalledWith(`/evidence/${row.ulid}/withdraw`, expect.any(Object));
        expect(confirm).toHaveBeenCalledTimes(2);
        confirm.mockRestore();
    });

    it('says when evidence was withdrawn or failed its integrity check, and shows the withdrawal reason as text', () => {
        const withdrawn = mount(Show, { props: { asset: { ...asset, status: 'withdrawn', withdrawnAt: row.capturedAt }, canDownload: false, canHold: false, canWithdraw: false,
            custody: [{ event: 'withdrawn', actor: 'Dana', occurredAt: row.capturedAt, reason: '<b>Fabricated</b>' }] } });
        expect(withdrawn.get('[role="note"]').text()).toContain('Withdrawn on');
        expect(withdrawn.get('[role="note"]').text()).toContain('flagged for re-review');
        expect(withdrawn.text()).toContain('Reason: <b>Fabricated</b>');
        expect(withdrawn.find('b').exists()).toBe(false);
        expect(withdrawn.text()).toContain('a'.repeat(64));

        const broken = mount(Show, { props: { asset: { ...asset, integrity: 'mismatch' }, canDownload: false, canHold: false, custody: [] } });
        expect(broken.get('[role="note"]').text()).toContain('Integrity check failed');
        expect(mount(Show, { props: { asset, canDownload: false, canHold: false, custody: [] } }).find('[role="note"]').exists()).toBe(false);
    });
});
