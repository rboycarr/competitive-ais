// Display names for source codes (docs/02 §3.4); an unknown code is shown as it is.
const TYPES: Record<string, string> = {
    website: 'Website', rss: 'RSS or Atom feed', api: 'API', licensed_feed: 'Licensed feed', public_filing: 'Public filing',
    social: 'Social media', internal: 'Internal', field: 'Field report', other: 'Other',
};
const METHODS: Record<string, string> = {
    manual: 'Manual', rss: 'Feed (RSS/Atom)', api: 'API', http_fetch: 'HTTP fetch', browser: 'Headless browser',
};

export const typeLabel = (type: string): string => TYPES[type] ?? type;
export const methodLabel = (method: string): string => METHODS[method] ?? method;
