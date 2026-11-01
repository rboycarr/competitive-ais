import { describe, expect, it } from 'vitest';
import { pagePath, resolvePage } from './pages';

describe('page resolution', () => {
    it('maps a page name to its module folder', () => {
        expect(pagePath('Identity/AcceptInvitation')).toBe('../../modules/Identity/resources/js/Pages/Identity/AcceptInvitation.vue');
    });

    it('rejects names that could leave the modules folder', () => {
        for (const name of ['../Secret', 'Platform/../../x', 'platform/Home', 'Home', 'Platform/Home.vue']) {
            expect(() => pagePath(name)).toThrow('Invalid page name');
        }
    });

    it('fails loudly for a missing page', () => {
        expect(() => resolvePage('Platform/Missing', {})).toThrow('Page not found: Platform/Missing');
    });

    it('loads a registered page', async () => {
        const page = { name: 'home' };
        await expect(resolvePage('Platform/Home', { '../../modules/Platform/resources/js/Pages/Platform/Home.vue': async () => page })).resolves.toBe(page);
    });
});
