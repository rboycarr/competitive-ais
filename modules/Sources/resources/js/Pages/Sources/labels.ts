// Display names for source codes (docs/02 §3.4); an unknown code is shown as it is.
const TYPES: Record<string, string> = {
    website: 'Website', rss: 'RSS or Atom feed', api: 'API', licensed_feed: 'Licensed feed', public_filing: 'Public filing',
    social: 'Social media', internal: 'Internal', field: 'Field report', other: 'Other',
};
const METHODS: Record<string, string> = {
    manual: 'Manual', rss: 'Feed (RSS/Atom)', api: 'API', http_fetch: 'HTTP fetch', browser: 'Headless browser',
};

const DECISIONS: Record<string, string> = { approved: 'Approved', rejected: 'Rejected', needs_info: 'Needs more information' };
const ROBOTS: Record<string, string> = {
    allowed: 'Allowed', disallowed: 'Disallowed', not_applicable: 'Not applicable', not_reviewed: 'Not reviewed',
};

export const decisionLabel = (decision: string): string => DECISIONS[decision] ?? decision;
export const robotsLabel = (robots: string): string => ROBOTS[robots] ?? robots;
export const typeLabel =(type: string): string => TYPES[type] ?? type;
export const methodLabel = (method: string): string => METHODS[method] ?? method;
