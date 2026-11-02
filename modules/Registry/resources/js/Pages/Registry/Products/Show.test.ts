import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const submit = vi.fn();
const post = vi.fn();
const put = vi.fn();
let transform: ((data: Record<string, unknown>) => Record<string, unknown>) | null = null;
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get: vi.fn() },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial, errors: {}, processing: false, submit, post, put, reset: vi.fn(), clearErrors: vi.fn(),
                transform: (fn: typeof transform) => { transform = fn; return form; },
            });

            return form;
        },
    };
});

const { default: Show } = await import('./Show.vue');
const { default: Index } = await import('./Index.vue');

const acme = { ulid: '01JACME0000000000000000000', name: '<i>Acme</i>' };
const options = {
    canManage: true, statuses: ['active', 'discontinued', 'rumored'], competitors: [acme],
    categories: [{ id: 1, name: 'Snacks', depth: 0 }, { id: 2, name: 'Chips', depth: 1 }],
    brands: [{ ulid: '01JBRANDA00000000000000000', name: 'Crunch', owner: acme.ulid }, { ulid: '01JBRANDO00000000000000000', name: 'Ours', owner: null }],
    classifications: ['public', 'internal'],
};
const product = {
    ulid: '01JPROD0000000000000000000', name: '<b>Crunch</b> Original', owner: acme, brand: { ulid: '01JBRANDA00000000000000000', name: 'Crunch' },
    category: { id: 2, name: 'Chips' }, status: 'active', launchedOn: '2020-03-01', discontinuedOn: null, classification: 'internal', description: '<script>x</script>',
};
const variant = { ulid: '01JVAR00000000000000000000', sku: 'CR-150', gtin: '2000000000015', name: 'Crunch 150 g', packSize: '150.5000', packUnit: 'g', packCount: 6, status: 'active' };
const showProps = { product, variants: [variant], units: ['g', 'kg'], variantStatuses: ['active', 'discontinued'], ...options };

describe('Registry/Products', () => {
    it('shows the product and its variants as text', () => {
        const wrapper = mount(Show, { props: showProps });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.get(`a[href="/competitors/${acme.ulid}"]`).text()).toBe('<i>Acme</i>');
        expect(wrapper.text()).toContain('150.5 g × 6');
    });

    it('offers only the owner\'s brands and never sends a new owner when editing', async () => {
        const wrapper = mount(Show, { props: showProps });
        await wrapper.get('#edit-heading + button').trigger('click');
        expect(wrapper.findAll('#edit-brand option').map((o) => o.text())).toEqual(['No brand', 'Crunch']);
        expect(wrapper.find('#edit-owner').exists()).toBe(false);
        await wrapper.get('section[aria-labelledby="edit-heading"] form').trigger('submit');
        expect(submit).toHaveBeenCalledWith('put', `/products/${product.ulid}`, expect.any(Object));
        expect(transform?.({ competitor: acme.ulid, name: 'X' })).toEqual({ competitor: undefined, name: 'X' });
    });

    it('edits a variant through its own URL', async () => {
        const wrapper = mount(Show, { props: showProps });
        await wrapper.get('button[aria-label="Edit variant CR-150"]').trigger('click');
        expect((wrapper.get('#variant-size').element as HTMLInputElement).value).toBe('150.5');
        await wrapper.get('section[aria-labelledby="variants-heading"] form').trigger('submit');
        expect(put).toHaveBeenCalledWith(`/products/${product.ulid}/variants/${variant.ulid}`, expect.any(Object));
    });

    it('lists products and switches brands with the owner when adding', async () => {
        const wrapper = mount(Index, { props: { products: [{ ...product, owner: null }], filters: {}, ...options } });
        expect(wrapper.find('tbody b').exists()).toBe(false);
        expect(wrapper.get('tbody').text()).toContain('Ours');
        expect(wrapper.findAll('#new-brand option').map((o) => o.text())).toEqual(['No brand', 'Ours']);
        await wrapper.get('#new-owner').setValue(acme.ulid);
        expect(wrapper.findAll('#new-brand option').map((o) => o.text())).toEqual(['No brand', 'Crunch']);
        await wrapper.get('section[aria-labelledby="add-product-heading"] form').trigger('submit');
        expect(submit).toHaveBeenLastCalledWith('post', '/products', expect.any(Object));
    });

    it('offers no changes to readers', () => {
        const wrapper = mount(Show, { props: { ...showProps, canManage: false, brands: [], classifications: [] } });
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.findAll('button').length).toBe(0);
    });
});
