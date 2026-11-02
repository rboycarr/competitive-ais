import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const put = vi.fn();
const get = vi.fn();
const del = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get, delete: del },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => reactive({ ...initial, errors: {}, processing: false, post, put, reset: vi.fn(), clearErrors: vi.fn() }),
    };
});

const { default: Retailers } = await import('./Retailers.vue');
const { default: Currencies } = await import('./Currencies.vue');

const markets = [{ ulid: '01JMARKET00000000000000000', code: 'PH', name: 'Synthetic' }];
const channels = [{ id: 5, name: 'Supermarkets' }, { id: 6, name: 'Online' }];
const retailers = [
    { ulid: '01JRETAIL00000000000000000', name: '<b>Shop</b>', website: 'https://shop.example', market: 'PH', channel: 5 },
    { ulid: '01JRETAIL00000000000000001', name: 'Other', website: 'javascript:alert(1)', market: 'PH', channel: 6 },
];

describe('Registry/Admin/Retailers', () => {
    it('links only http(s) websites and shows names as text', () => {
        const wrapper = mount(Retailers, { props: { retailers, markets, channels, filters: {} } });
        const links = wrapper.findAll('a[target="_blank"]');
        expect(links.map((a) => a.attributes('href'))).toEqual(['https://shop.example']);
        expect(links[0]?.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('Supermarkets');
    });

    it('filters by market and channel, and edits a retailer at its own URL', async () => {
        const wrapper = mount(Retailers, { props: { retailers, markets, channels, filters: {} } });
        await wrapper.get('#filter-channel').setValue('6');
        await wrapper.get('form').trigger('submit');
        expect(get).toHaveBeenCalledWith('/admin/retailers', { channel: '6' }, expect.any(Object));

        await wrapper.get('button[aria-label="Edit retailer <b>Shop</b>"]').trigger('click');
        await wrapper.get(`#edit-name-${retailers[0]?.ulid}`).setValue('Shop Two');
        await wrapper.get('td form').trigger('submit');
        expect(put).toHaveBeenCalledWith(`/admin/retailers/${retailers[0]?.ulid}`, expect.any(Object));
    });

    it('asks first for a market and a channel', () => {
        const wrapper = mount(Retailers, { props: { retailers: [], markets, channels: [], filters: {} } });
        expect(wrapper.text()).toContain('Add a market and a channel first.');
        expect(wrapper.find('#retailer-name').exists()).toBe(false);
    });
});

describe('Registry/Admin/Currencies', () => {
    const currencies = [{ code: 'PHP', name: 'Philippine Peso', minorUnits: 2, markets: 1 }, { code: 'XTS', name: 'Test Units', minorUnits: 2, markets: 0 }];

    it('keeps decimal places fixed for currencies that markets use', async () => {
        const wrapper = mount(Currencies, { props: { currencies } });
        await wrapper.get('button[aria-label="Edit currency PHP"]').trigger('click');
        expect(wrapper.get('#edit-units-PHP').attributes('disabled')).toBeDefined();
        await wrapper.get('td form').trigger('submit');
        expect(put).toHaveBeenLastCalledWith('/admin/currencies/PHP', expect.any(Object));

        await wrapper.get('button[aria-label="Edit currency XTS"]').trigger('click');
        expect(wrapper.get('#edit-units-XTS').attributes('disabled')).toBeUndefined();
    });
});
