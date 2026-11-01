import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const formPut = vi.fn();
const del = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { delete: del },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => reactive({ ...initial, errors: {}, processing: false, post, put: formPut, reset: vi.fn(), clearErrors: vi.fn() }),
    };
});

const { default: Index } = await import('./Index.vue');

const acme = { ulid: '01JACME0000000000000000000', name: '<i>Acme</i> Foods' };
const props = {
    brands: [
        { ulid: '01JOWN00000000000000000000', name: 'Our Crisps', owner: null },
        { ulid: '01JBRAND000000000000000000', name: '<b>Crunch</b>', owner: acme },
    ],
    canManage: true,
    competitors: [acme],
};

describe('Registry/Brands/Index', () => {
    it('groups our brands and each competitor, as text', () => {
        const wrapper = mount(Index, { props });
        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['Our brands', '<i>Acme</i> Foods', 'Add a brand']);
        expect(wrapper.get(`a[href="/competitors/${acme.ulid}"]`).text()).toBe('<i>Acme</i> Foods');
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.find('i').exists()).toBe(false);
    });

    it('adds, renames and asks before removing', async () => {
        const wrapper = mount(Index, { props });
        await wrapper.get('#brand-owner').setValue(acme.ulid);
        await wrapper.get('#brand-name').setValue('Acme Max');
        await wrapper.get('section[aria-labelledby="add-brand-heading"] form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/brands', expect.any(Object));

        await wrapper.get('button[aria-label="Rename brand Our Crisps"]').trigger('click');
        await wrapper.get('#rename-01JOWN00000000000000000000').setValue('Our Chips');
        await wrapper.get('li form').trigger('submit');
        expect(formPut).toHaveBeenCalledWith('/brands/01JOWN00000000000000000000', expect.any(Object));

        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        await wrapper.get('button[aria-label="Remove brand <b>Crunch</b>"]').trigger('click');
        expect(del).not.toHaveBeenCalled();
        await wrapper.get('button[aria-label="Remove brand <b>Crunch</b>"]').trigger('click');
        expect(del).toHaveBeenCalledWith('/brands/01JBRAND000000000000000000', expect.any(Object));
        confirm.mockRestore();
    });

    it('offers no changes to readers', () => {
        const wrapper = mount(Index, { props: { ...props, canManage: false, competitors: [] } });
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.findAll('button').length).toBe(0);
    });
});
