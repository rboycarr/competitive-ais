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
const options = { filters: {}, statuses: ['quarantined', 'available', 'rejected', 'withdrawn'], maxBytes: 50 * 1024 * 1024, competitors: [], markets: [] };

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
        expect([transformed?.competitors, transformed?.markets]).toEqual([[], []]);
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
});

describe('Evidence/Show', () => {
    const asset = {
        ulid: row.ulid, title: 'Shelf', description: '<img src=x onerror=alert(1)>', status: 'quarantined', scanStatus: 'pending', originalFilename: '<i>a</i>.pdf',
        sizeBytes: 2048, sourceUrl: 'https://shop.example/item?id=7', capturedAt: row.capturedAt, uploadedBy: 'Ana', uploadedAt: row.capturedAt, classification: 'internal', legalHold: false,
        competitors: ['<b>Nord</b>'], markets: [],
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
