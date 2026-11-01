import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const submit = vi.fn();
const post = vi.fn();
const put = vi.fn();
const del = vi.fn();
let transform: ((data: Record<string, unknown>) => Record<string, unknown>) | null = null;
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { put, delete: del },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial, errors: {}, processing: false, submit, post, put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(),
                transform: (fn: typeof transform) => { transform = fn; return form; },
            });

            return form;
        },
    };
});

const { default: Show } = await import('./Show.vue');

const competitor = {
    ulid: '01JCOMP0000000000000000000', name: 'Acme Foods', legalName: null, website: 'https://acme.example', hqCountry: 'PH', lifecycle: 'active',
    tier: 'primary', analyst: null, classification: 'internal', description: '<b>not bold</b>',
};
const relationship = {
    ulid: '01JREL00000000000000000000', type: 'subsidiary', role: 'child' as const, other: { ulid: '01JOTHER000000000000000000', name: '<i>Beta</i> Holdings' },
    validFrom: '2020-03-01', validFromPrecision: 'month', validTo: null, notes: '<b>note</b>',
};
const props = {
    competitor, lifecycles: ['active', 'watch', 'dormant', 'archived'], canManage: true, tiers: ['primary'], analysts: [], classifications: ['public', 'internal'],
    aliases: [{ id: 7, alias: '<i>ACME</i>', type: 'brand' }], aliasTypes: ['brand', 'trade', 'former', 'abbreviation', 'local'],
    relationships: [relationship], relationTypes: ['subsidiary', 'brand_owner', 'joint_venture', 'partner', 'distributor'],
    precisions: ['day', 'month', 'year', 'unknown'], others: [relationship.other],
};

describe('Registry/Competitors/Show', () => {
    it('links only http(s) websites and shows text as text', () => {
        const wrapper = mount(Show, { props });
        const link = wrapper.get('a[target="_blank"]');
        expect(link.attributes('href')).toBe('https://acme.example');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.find('i').exists()).toBe(false); // alias and competitor names too

        const unsafe = mount(Show, { props: { ...props, competitor: { ...competitor, website: 'javascript:alert(1)' } } });
        expect(unsafe.find('a[target="_blank"]').exists()).toBe(false);
    });

    it('saves the profile to its own URL and asks before archiving', async () => {
        const wrapper = mount(Show, { props });
        await wrapper.get('#edit-heading + button').trigger('click');
        await wrapper.get('#edit-name').setValue('Acme Foods Inc');
        await wrapper.get('section[aria-labelledby="edit-heading"] form').trigger('submit');
        expect(submit).toHaveBeenCalledWith('put', `/competitors/${competitor.ulid}`, expect.any(Object));

        vi.spyOn(window, 'confirm').mockReturnValueOnce(false);
        await wrapper.get('#lifecycle').setValue('archived');
        await wrapper.get('section[aria-labelledby="lifecycle-heading"] form').trigger('submit');
        expect(put).not.toHaveBeenCalled();
    });

    it('adds aliases and asks before removing one', async () => {
        const wrapper = mount(Show, { props });
        await wrapper.get('#alias-name').setValue('Acme Brands');
        await wrapper.get('section[aria-labelledby="aliases-heading"] form').trigger('submit');
        expect(post).toHaveBeenCalledWith(`/competitors/${competitor.ulid}/aliases`, expect.any(Object));

        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        await wrapper.get('button[aria-label="Remove alias <i>ACME</i>"]').trigger('click');
        expect(del).not.toHaveBeenCalled();
        await wrapper.get('button[aria-label="Remove alias <i>ACME</i>"]').trigger('click');
        expect(del).toHaveBeenCalledWith(`/competitors/${competitor.ulid}/aliases/7`, expect.any(Object));
        confirm.mockRestore();
    });

    it('phrases relationships from this competitor and sends the type and role', async () => {
        const wrapper = mount(Show, { props });
        const section = wrapper.get('section[aria-labelledby="relationships-heading"]');
        expect(section.text()).toContain('Is a subsidiary of <i>Beta</i> Holdings · from 2020-03 to present');
        expect(section.get(`a[href="/competitors/${relationship.other.ulid}"]`).text()).toBe('<i>Beta</i> Holdings');

        await wrapper.get('#relationship-kind').setValue('brand_owner:parent');
        await wrapper.get('#relationship-other').setValue(relationship.other.ulid);
        await wrapper.get('#relationship-from').setValue('2021-06-15');
        await section.get('form').trigger('submit');
        expect(post).toHaveBeenLastCalledWith(`/competitors/${competitor.ulid}/relationships`, expect.any(Object));
        expect(transform?.({ kind: 'brand_owner:parent', other: relationship.other.ulid, valid_from: '2021-06-15', valid_from_precision: 'day', valid_to: '', notes: '' })).toEqual({
            role: 'parent', relation_type: 'brand_owner', other: relationship.other.ulid, valid_from: '2021-06-15', valid_from_precision: 'day', valid_to: '', notes: '',
        });
        // "Partner" is symmetric: offered once.
        expect(wrapper.findAll('#relationship-kind option').map((o) => o.attributes('value')).filter((v) => v?.startsWith('partner'))).toEqual(['partner:parent']);
    });

    it('offers no changes to readers', () => {
        const wrapper = mount(Show, { props: { ...props, canManage: false, others: [] } });
        expect(wrapper.find('#alias-name').exists()).toBe(false);
        expect(wrapper.find('#relationship-kind').exists()).toBe(false);
        expect(wrapper.findAll('button').length).toBe(0);
    });
});
