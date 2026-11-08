// Phase 1 exit journey (P1-47, docs/00 §3 J1 and J6), on the data of database/seeders/E2eSeeder.php.
// J1's capture steps (competitor, source policy, upload through ClamAV, claims) are seeded through the real services
// and covered by the backend feature tests; here the browser runs its decision steps and J6 end to end.
import { expect, test, type Page } from '@playwright/test';
import { authFile, type E2eUser } from './support';

async function as(browser: import('@playwright/test').Browser, user: E2eUser): Promise<Page> {
    return (await browser.newContext({ storageState: authFile(user), baseURL: test.info().project.use.baseURL })).newPage();
}

async function openItem(page: Page, title: string): Promise<void> {
    await page.goto('/intelligence');
    await page.getByRole('link', { name: title }).click();
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
}

test('J1: a director assigns, a reviewer verifies, the director publishes', async ({ browser }) => {
    const title = 'Acme opens a new plant';
    const director = await as(browser, 'director');
    const reviewer = await as(browser, 'reviewer');

    // The submitted item waits in the verification queue.
    await director.goto('/verification');
    await expect(director.getByRole('link', { name: title })).toBeVisible();

    await openItem(director, title);
    await director.getByLabel('Assign a reviewer').selectOption({ label: 'E2E Reviewer' });
    await director.getByRole('button', { name: 'Assign' }).click();
    await expect(director.getByText('Assigned to E2E Reviewer')).toBeVisible();

    await openItem(reviewer, title);
    await reviewer.getByRole('button', { name: 'Start review' }).click();
    await reviewer.getByRole('radio', { name: 'Approve' }).check();
    await reviewer.getByRole('button', { name: 'Record decision' }).click();
    await expect(reviewer.getByText('Approved', { exact: false }).first()).toBeVisible();

    await openItem(director, title);
    await director.getByRole('button', { name: 'Publish' }).click();
    await expect(director.getByText('Published', { exact: false }).first()).toBeVisible();

    // The analyst's dashboard now lists it as recently published.
    const analyst = await as(browser, 'analyst');
    await analyst.goto('/');
    await expect(analyst.locator('[data-tile="recently_published"]').getByRole('link', { name: title })).toBeVisible();
});

test('J6: an auditor traces a published item to its evidence, source, reviews and audit trail', async ({ browser }) => {
    const title = 'Acme cuts widget prices';
    const auditor = await as(browser, 'auditor');

    await openItem(auditor, title);
    await auditor.getByRole('link', { name: 'Provenance' }).click();

    const claims = auditor.getByRole('region', { name: 'Claims and their evidence' });
    await expect(claims.getByText(`Synthetic claim: ${title}`)).toBeVisible();
    await expect(claims.getByText(`Evidence for: ${title}`)).toBeVisible();
    await expect(claims.getByText('Synthetic Trade News')).toBeVisible();

    const reviews = auditor.getByRole('region', { name: 'Reviews' });
    await expect(reviews.getByText('Approved', { exact: false }).first()).toBeVisible();

    const audit = auditor.getByRole('region', { name: 'Audit trail' });
    await expect(audit.getByText('intelligence.published', { exact: false }).first()).toBeVisible();
});
