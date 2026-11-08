// End-to-end exit journey (P1-47, docs/09 item 9). Runs in the `e2e` Compose service (Playwright's own browser image)
// against nginx, after database/seeders/E2eSeeder.php: bash scripts/ci/e2e.sh
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: '*.e2e.ts',
    globalSetup: './global-setup.ts',
    // One worker: the journey changes shared state (the submitted item is decided and published).
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 60_000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://nginx',
        trace: 'retain-on-failure',
    },
});
