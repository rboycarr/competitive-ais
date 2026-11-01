import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const form = reactive({ name: '', password: '', password_confirmation: '', errors: {} as Record<string, string>, processing: false, post, reset: vi.fn() });
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, useForm: () => form }));

const { default: AcceptInvitation } = await import('./AcceptInvitation.vue');
const token = 'a'.repeat(64);

describe('Identity/AcceptInvitation', () => {
    it('labels every field and posts to the invitation URL', async () => {
        const wrapper = mount(AcceptInvitation, { props: { email: 'new@example.test', expiresAt: '2026-11-05T00:00:00Z', token } });

        for (const id of ['name', 'password', 'password_confirmation']) {
            expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
        }
        expect(wrapper.text()).toContain('new@example.test');

        await wrapper.get('form').trigger('submit');
        expect(post).toHaveBeenCalledWith(`/invitations/${token}`, expect.any(Object));
    });

    it('announces validation errors', async () => {
        form.errors = { password: 'The password field must be at least 12 characters.' };
        const wrapper = mount(AcceptInvitation, { props: { email: 'new@example.test', expiresAt: '2026-11-05T00:00:00Z', token } });

        const error = wrapper.get('#password-error');
        expect(error.attributes('role')).toBe('alert');
        expect(wrapper.get('#password').attributes('aria-invalid')).toBe('true');
    });
});
