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

const ACTIONS: Record<string, string> = { suspend: 'Suspend', reinstate: 'Reinstate', retire: 'Retire' };
const ACTION_HELP: Record<string, string> = {
    suspend: 'Takes effect at once: nobody may use the source until it is reinstated and approved again.',
    reinstate: 'Sends the source for a new policy review; a director who did not propose it must approve a new policy version.',
    retire: 'Final: a retired source cannot be used or reinstated. It stays on record with its history.',
};
const REASONS: Record<string, string> = {
    terms_changed: 'Terms changed', access_blocked: 'Access blocked', complaint: 'Complaint', error_rate: 'Too many errors',
    resolved: 'Issue resolved', no_longer_needed: 'No longer needed', other: 'Other',
};
const STATUSES: Record<string, string> = { proposed: 'Proposed', policy_review: 'Policy review', approved: 'Approved', suspended: 'Suspended', retired: 'Retired' };

export const actionLabel = (action: string): string => ACTIONS[action] ?? action;
export const actionHelp = (action: string): string => ACTION_HELP[action] ?? '';
export const reasonLabel = (reason: string): string => REASONS[reason] ?? reason;
export const statusLabel = (status: string): string => STATUSES[status] ?? status;
export const decisionLabel =(decision: string): string => DECISIONS[decision] ?? decision;
export const robotsLabel = (robots: string): string => ROBOTS[robots] ?? robots;
export const typeLabel =(type: string): string => TYPES[type] ?? type;
export const methodLabel = (method: string): string => METHODS[method] ?? method;
