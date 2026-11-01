import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: { render: () => null },
        Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
        router: { get },
        usePage: () => ({ props: {} }),
    };
});

const { default: AuditLog } = await import('./AuditLog.vue');

const event = {
    ulid: '01JAUDIT00000000000000000A', occurredAt: '2026-11-01T08:00:00.000000Z', actor: 'Ana', action: 'team.created',
    subjectType: 'Team', subjectUlid: '01JTEAM000000000000000000A', classification: 'internal', requestId: null,
    context: { lead: '<img src=x onerror=alert(1)>', fields: ['name', 'lead_user_id'] },
};
const props = { events: [event], nextUrl: '/admin/audit?cursor=abc', previousUrl: null, filters: {}, actions: ['team.created'], users: [{ ulid: 'U'.repeat(26), name: 'Ana' }] };

describe('Identity/Admin/AuditLog', () => {
    it('labels the filters and sends only the filled ones, times in UTC', async () => {
        const wrapper = mount(AuditLog, { props });
        for (const id of ['audit-actor', 'audit-action', 'audit-subject', 'audit-from', 'audit-to']) {
            expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
        }

        await wrapper.get('#audit-action').setValue('team.created');
        await wrapper.get('#audit-from').setValue('2026-11-01T09:30');
        await wrapper.get('form').trigger('submit');

        expect(get).toHaveBeenCalledWith('/admin/audit', { action: 'team.created', from: new Date('2026-11-01T09:30').toISOString() }, expect.any(Object));
    });

    it('shows details as text and links to older events', () => {
        const wrapper = mount(AuditLog, { props });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('lead: <img src=x onerror=alert(1)>; fields: name, lead_user_id');
        expect(wrapper.get('nav a').attributes('href')).toBe('/admin/audit?cursor=abc');
    });
});
