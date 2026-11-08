import { createHmac } from 'node:crypto';
import type { Browser, Page } from '@playwright/test';

// The users database/seeders/E2eSeeder.php creates; their password and TOTP secret come from the environment.
export const USERS = ['analyst', 'reviewer', 'director', 'auditor'] as const;
export type E2eUser = (typeof USERS)[number];

export const authFile = (user: E2eUser): string => `e2e/.auth/${user}.json`;

function env(name: string): string {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not set`);
    }

    return value;
}

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s), as an authenticator app computes it. */
export function totp(secret: string, now: number = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 30_000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;

    return ((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
}

/** Signs in through the real login and TOTP challenge pages; returns the signed-in page. */
export async function signIn(browser: Browser, user: E2eUser, baseURL: string): Promise<Page> {
    const page = await (await browser.newContext({ baseURL })).newPage();
    await page.goto('/login');
    await page.getByLabel('Email').fill(`e2e.${user}@example.test`);
    await page.getByLabel('Password').fill(env('E2E_PASSWORD'));
    await page.getByRole('button', { name: 'Sign in' }).click();
    await reach(page, (url) => url.pathname === '/two-factor-challenge', `${user}: password step`);
    await page.getByLabel('Code from your authenticator app').fill(totp(env('E2E_TOTP_SECRET')));
    await page.getByRole('button', { name: 'Continue' }).click();
    await reach(page, (url) => !url.pathname.startsWith('/two-factor-challenge') && !url.pathname.startsWith('/login'), `${user}: TOTP step`);

    return page;
}

/** Waits for the URL; on timeout, fails with what the page says (errors are shown in the page), not just "timeout". */
async function reach(page: Page, test: (url: URL) => boolean, step: string): Promise<void> {
    try {
        await page.waitForURL(test, { timeout: 15_000 });
    } catch {
        // No " at " in the message: the CI annotation drops such lines as stack frames (scripts/ci/lib.sh).
        const text = (await page.locator('main').innerText().catch(() => '')).replace(/\s+/g, ' ').replace(/\bat\b/g, '@').slice(0, 300);
        throw new Error(`Sign-in failed in the ${step}; URL ${page.url()}; page text: ${text}`);
    }
}
