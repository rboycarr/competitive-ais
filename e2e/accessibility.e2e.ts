// Accessibility of the core pages (P1-47, docs/09 item 9): axe reports no serious or critical violations.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { authFile } from './support';

const PAGES = ['/', '/competitors', '/products', '/sources', '/evidence', '/intelligence', '/verification', '/admin/audit'];

async function serious(page: import('@playwright/test').Page): Promise<string[]> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

    return results.violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map((v) => `${v.id} (${v.impact}): ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target.join(' ')}`);
}

test('the sign-in page', async ({ page }) => {
    await page.goto('/login');
    expect(await serious(page)).toEqual([]);
});

test.describe('signed-in pages (director)', () => {
    test.use({ storageState: authFile('director') });

    for (const path of PAGES) {
        test(path, async ({ page }) => {
            await page.goto(path);
            expect(await serious(page)).toEqual([]);
        });
    }

    test('an item and its provenance', async ({ page }) => {
        await page.goto('/intelligence');
        await page.getByRole('link', { name: 'Acme cuts widget prices' }).click();
        expect(await serious(page)).toEqual([]);
        await page.getByRole('link', { name: 'Provenance' }).click();
        expect(await serious(page)).toEqual([]);
    });
});
