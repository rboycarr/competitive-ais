import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const put = vi.fn();
const del = vi.fn();
let transform: ((data: Record<string, unknown>) => Record<string, unknown>) | null = null;
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { delete: del },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial, errors: {}, processing: false, post, put, reset: vi.fn(), clearErrors: vi.fn(),
                transform: (fn: typeof transform) => { transform = fn; return form; },
            });

            return form;
        },
    };
});

const { default: Market } = await import('./Market.vue');
const { default: Markets } = await import('./Markets.vue');

const market = { ulid: '01JMARKET00000000000000000', code: 'PH', name: '<b>Synthetic</b>', country: 'PH', currency: 'PHP' };
const regions = [
    { id: 1, name: 'North', level: 'region', depth: 0 },
    { id: 2, name: 'Alpha', level: 'province', depth: 1 },
    { id: 3, name: 'Gamma', level: 'district', depth: 2 },
];
const props = { market, regions, levels: ['region', 'province', 'city', 'district'], currencies: [{ code: 'PHP', name: 'Philippine Peso' }] };

describe('Registry/Admin/Market', () => {
    it('offers only parents with a deeper level left, and only deeper levels under them', async () => {
        const wrapper = mount(Market, { props });
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.findAll('#region-parent option').map((o) => o.text())).toEqual(['The whole market', 'North (region)', '— Alpha (province)']);
        expect(wrapper.findAll('#region-level option').map((o) => o.text())).toEqual(['region', 'province', 'city', 'district']);
        await wrapper.get('#region-parent').setValue(2);
        expect(wrapper.findAll('#region-level option').map((o) => o.text())).toEqual(['city', 'district']);

        await wrapper.get('#region-name').setValue('Beta');
        await wrapper.get('section[aria-labelledby="add-region-heading"] form').trigger('submit');
        expect(post).toHaveBeenCalledWith(`/admin/markets/${market.ulid}/regions`, expect.any(Object));
        expect(transform?.({ name: 'Beta', level: 'city', parent: '' })).toEqual({ name: 'Beta', level: 'city', parent: null });
    });

    it('saves details and asks before removing a region', async () => {
        const wrapper = mount(Market, { props });
        await wrapper.get('section[aria-labelledby="details-heading"] form').trigger('submit');
        expect(put).toHaveBeenCalledWith(`/admin/markets/${market.ulid}`, expect.any(Object));

        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        await wrapper.get('button[aria-label="Remove region North"]').trigger('click');
        expect(del).not.toHaveBeenCalled();
        await wrapper.get('button[aria-label="Remove region North"]').trigger('click');
        expect(del).toHaveBeenCalledWith(`/admin/markets/${market.ulid}/regions/1`, expect.any(Object));
        confirm.mockRestore();
    });

    it('lists markets as text and adds one', async () => {
        const wrapper = mount(Markets, { props: { markets: [{ ...market, regions: 3 }], currencies: props.currencies } });
        expect(wrapper.find('tbody b').exists()).toBe(false);
        expect(wrapper.get(`a[href="/admin/markets/${market.ulid}"]`).text()).toBe('PH');
        await wrapper.get('section[aria-labelledby="add-market-heading"] form').trigger('submit');
        expect(post).toHaveBeenLastCalledWith('/admin/markets', expect.any(Object));
    });
});
