import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const put = vi.fn();
const del = vi.fn();
let transform: ((data: Record<string, unknown>) => Record<string, unknown>) | null = null;
const pageErrors: Record<string, string> = {};
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { delete: del },
        usePage: () => ({ props: { errors: pageErrors } }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial, errors: {}, processing: false, post, put, reset: vi.fn(), clearErrors: vi.fn(),
                transform: (fn: typeof transform) => { transform = fn; return form; },
            });

            return form;
        },
    };
});

const { default: Categories } = await import('./Categories.vue');

const categories = [
    { id: 1, parentId: null, name: 'Snacks', depth: 0, path: '/' },
    { id: 2, parentId: 1, name: 'Chips', depth: 1, path: '/1/' },
    { id: 3, parentId: 2, name: 'Corn chips', depth: 2, path: '/1/2/' },
    { id: 4, parentId: null, name: '<b>Drinks</b>', depth: 0, path: '/' },
];

describe('Registry/Admin/Categories', () => {
    it('never offers a category or its subcategories as its new place', async () => {
        const wrapper = mount(Categories, { props: { categories, maxDepth: 5 } });
        expect(wrapper.find('b').exists()).toBe(false);
        await wrapper.get('button[aria-label="Move category Chips"]').trigger('click');
        expect(wrapper.findAll('#move-2 option').map((o) => o.text())).toEqual(['Top level', 'Snacks', '<b>Drinks</b>']);

        await wrapper.get('#move-2').setValue('');
        await wrapper.get('li form').trigger('submit');
        expect(put).toHaveBeenLastCalledWith('/admin/categories/2/parent', expect.any(Object));
        expect(transform?.({ name: 'Chips', parent: '' })).toEqual({ parent: null });
    });

    it('offers only parents with room for another level', async () => {
        const wrapper = mount(Categories, { props: { categories, maxDepth: 2 } });
        expect(wrapper.findAll('#category-parent option').map((o) => o.text())).toEqual(['Top level', 'Snacks', '— Chips', '<b>Drinks</b>']);
        await wrapper.get('#category-name').setValue('Crackers');
        await wrapper.get('#category-parent').setValue(1);
        await wrapper.get('section[aria-labelledby="add-category-heading"] form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/admin/categories', expect.any(Object));
        expect(transform?.({ name: 'Crackers', parent: 1 })).toEqual({ name: 'Crackers', parent: 1 });
        expect(transform?.({ name: 'Crackers', parent: '' })).toEqual({ name: 'Crackers', parent: null }); // top level
    });

    it('asks before removing and shows a refused removal', async () => {
        pageErrors.category = 'Move or remove its subcategories first.';
        const wrapper = mount(Categories, { props: { categories, maxDepth: 5 } });
        expect(wrapper.get('[role="alert"]').text()).toBe('Move or remove its subcategories first.');
        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        await wrapper.get('button[aria-label="Remove category Snacks"]').trigger('click');
        expect(del).not.toHaveBeenCalled();
        await wrapper.get('button[aria-label="Remove category Snacks"]').trigger('click');
        expect(del).toHaveBeenCalledWith('/admin/categories/1', expect.any(Object));
        confirm.mockRestore();
    });
});
