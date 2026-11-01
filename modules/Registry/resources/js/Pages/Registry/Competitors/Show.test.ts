import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const submit = vi.fn();
const put = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { put },
        usePage: () => ({ props: {} }),
        useForm: (initial: Record<string, unknown>) => reactive({ ...initial, errors: {}, processing: false, submit }),
    };
});

const { default: Show } = await import('./Show.vue');

const competitor = {
    ulid: '01JCOMP0000000000000000000', name: 'Acme Foods', legalName: null, website: 'https://acme.example', hqCountry: 'PH', lifecycle: 'active',
    tier: 'primary', analyst: null, classification: 'internal', description: '<b>not bold</b>',
};
const props = { competitor, lifecycles: ['active', 'watch', 'dormant', 'archived'], canManage: true, tiers: ['primary'], analysts: [], classifications: ['public', 'internal'] };

describe('Registry/Competitors/Show', () => {
    it('links only http(s) websites and shows text as text', () => {
        const wrapper = mount(Show, { props });
        const link = wrapper.get('a[target="_blank"]');
        expect(link.attributes('href')).toBe('https://acme.example');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(wrapper.find('b').exists()).toBe(false);

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
});
