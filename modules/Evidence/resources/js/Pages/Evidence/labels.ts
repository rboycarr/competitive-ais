// Display names for evidence codes (docs/02 §3.5); an unknown code is shown as it is.
const STATUSES: Record<string, string> = { quarantined: 'In quarantine', available: 'Available', rejected: 'Rejected', withdrawn: 'Withdrawn' };
const SCANS: Record<string, string> = { pending: 'Waiting to be scanned', clean: 'Clean', infected: 'Malware found', error: 'Scan failed; it will be retried' };
const EVENTS: Record<string, string> = {
    uploaded: 'Uploaded', scanned: 'Scanned', rejected: 'Rejected', viewed: 'Viewed', downloaded: 'Downloaded', linked: 'Linked to a claim',
    unlinked: 'Unlinked from a claim', scope_inherited: 'Scope inherited', hold_set: 'Legal hold set', hold_released: 'Legal hold released',
    retention_changed: 'Retention changed', integrity_verified: 'Integrity verified', integrity_failed: 'Integrity check failed', withdrawn: 'Withdrawn',
};

export const statusLabel = (status: string): string => STATUSES[status] ?? status;
export const scanLabel = (scan: string): string => SCANS[scan] ?? scan;
export const eventLabel = (event: string): string => EVENTS[event] ?? event;

/** Bytes as a short human size (base 1024). */
export function sizeLabel(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(1)} ${units[unit]}`;
}
