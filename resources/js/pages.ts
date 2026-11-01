// Inertia pages live in their module (docs/01 §3): page "Identity/AcceptInvitation" is
// modules/Identity/resources/js/Pages/Identity/AcceptInvitation.vue.
const PAGE_NAME = /^[A-Z][A-Za-z0-9]*(\/[A-Z][A-Za-z0-9]*)+$/;

export function pagePath(name: string): string {
    if (!PAGE_NAME.test(name)) {
        throw new Error(`Invalid page name: ${name}`);
    }
    const module = name.split('/')[0];

    return `../../modules/${module}/resources/js/Pages/${name}.vue`;
}

export function resolvePage<T>(name: string, pages: Record<string, () => Promise<T>>): Promise<T> {
    const load = pages[pagePath(name)];
    if (load === undefined) {
        throw new Error(`Page not found: ${name}`);
    }

    return load();
}
