import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

type Data = Record<string, unknown>;
const post = vi.fn();
const routerPost = vi.fn();
const form = reactive({
    grantee_type: 'user', grantee: '', scope: 'all', competitor: '', market: '', max_classification: '', can_export: false, reason: '', expires_at: '',
    errors: {} as Record<string, string>, processing: false, post, reset: vi.fn(),
    transformer: (data: Data): Data => data,
    transform(callback: (data: Data) => Data) { this.transformer = callback; return this; },
});
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { post: routerPost },
        usePage: () => ({ props: {} }),
        useForm: () => form,
    };
});

const { default: AccessGrants } = await import('./AccessGrants.vue');

const grant = (status: 'active' | 'revoked') => ({
    ulid: `01J${status.toUpperCase().padEnd(23, '0')}`, granteeType: 'user' as const, grantee: 'Ana', scope: 'all', competitor: null, market: null, maxClassification: 'internal',
    canExport: false, reason: 'Pricing study', grantedBy: 'Dee', grantedAt: '2026-11-01T00:00:00Z', expiresAt: null,
    revokedBy: status === 'revoked' ? 'Dee' : null, revokedAt: status === 'revoked' ? '2026-11-01T01:00:00Z' : null, status,
});
const props = {
    grants: [grant('active'), grant('revoked')], users: [{ ulid: 'U'.repeat(26), name: 'Ana' }], teams: [],
    classifications: ['public', 'internal'], canGrantExport: false, competitors: [{ ulid: 'C'.repeat(26), name: 'Acme Foods' }],
    markets: [{ ulid: 'M'.repeat(26), name: 'PH · Synthetic' }],
};

describe('Identity/Admin/AccessGrants', () => {
    it('labels the fields, hides exports nobody may grant, and sends the expiry in UTC', async () => {
        const wrapper = mount(AccessGrants, { props });

        for (const id of ['grant-grantee', 'grant-classification', 'grant-reason', 'grant-expires']) {
            expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
        }
        expect(wrapper.text()).not.toContain('Allow exports');

        form.expires_at = '2026-12-01T09:30';
        await wrapper.get('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/admin/access-grants', expect.any(Object));
        expect(form.transformer({ expires_at: '2026-12-01T09:30' }).expires_at).toBe(new Date('2026-12-01T09:30').toISOString());
        expect(form.transformer({ expires_at: '' }).expires_at).toBeNull();
        // A competitor is sent only with the competitor scope.
        expect(form.transformer({ scope: 'all', competitor: 'C'.repeat(26), expires_at: '' }).competitor).toBeNull();
        expect(form.transformer({ scope: 'competitor', competitor: 'C'.repeat(26), expires_at: '' }).competitor).toBe('C'.repeat(26));
        // Likewise a market only with the market scope (P1-23c).
        expect(form.transformer({ scope: 'competitor', market: 'M'.repeat(26), expires_at: '' }).market).toBeNull();
        expect(form.transformer({ scope: 'market', market: 'M'.repeat(26), expires_at: '' }).market).toBe('M'.repeat(26));
    });

    it('offers the markets for a market grant and names the market of existing ones', async () => {
        const marketGrant = { ...grant('active'), ulid: '01JMARKETGRANT000000000000', scope: 'market', market: 'PH · Synthetic' };
        const wrapper = mount(AccessGrants, { props: { ...props, grants: [marketGrant] } });
        expect(wrapper.get('tbody').text()).toContain('PH · Synthetic');
        expect(wrapper.find('#grant-market').exists()).toBe(false);
        form.scope = 'market';
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('#grant-market option').map((o) => o.text())).toEqual(['Choose…', 'PH · Synthetic']);
        form.scope = 'all';
    });

    it('offers revoke only for grants that are not revoked, after confirmation', async () => {
        const wrapper = mount(AccessGrants, { props });
        const buttons = wrapper.findAll('tbody button');
        expect(buttons).toHaveLength(1);

        vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        await buttons[0]!.trigger('click');
        expect(routerPost).not.toHaveBeenCalled();
        await buttons[0]!.trigger('click');
        expect(routerPost).toHaveBeenCalledWith(`/admin/access-grants/${props.grants[0]!.ulid}/revoke`, {}, expect.any(Object));
    });
});
