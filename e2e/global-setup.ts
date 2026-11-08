import { chromium, type FullConfig } from '@playwright/test';
import { authFile, signIn, USERS } from './support';

// Each user signs in once (a TOTP code is accepted only once per user), and the session is reused by every test.
export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0]?.use.baseURL ?? 'http://nginx';
    const browser = await chromium.launch();
    try {
        for (const user of USERS) {
            const page = await signIn(browser, user, baseURL);
            await page.context().storageState({ path: authFile(user) });
            await page.context().close();
        }
    } finally {
        await browser.close();
    }
}
