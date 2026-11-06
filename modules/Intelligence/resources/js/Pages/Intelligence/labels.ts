// Display names for intelligence codes (docs/02 §3.6, ADR-0010); an unknown code is shown as it is.
const TYPES: Record<string, string> = {
    news: 'News', product_change: 'Product change', pricing_signal: 'Pricing signal', strategy: 'Strategy', leadership: 'Leadership',
    partnership: 'Partnership', regulatory: 'Regulatory', facility: 'Facility', financial: 'Financial', other: 'Other',
};
const STATUSES: Record<string, string> = {
    draft: 'Draft', submitted: 'Submitted', in_review: 'In review', changes_requested: 'Changes requested', approved: 'Approved',
    published: 'Published', rejected: 'Rejected', retracted: 'Retracted', superseded: 'Superseded',
};
const CLAIM_TYPES: Record<string, string> = {
    observed_fact: 'Observed fact', reported_claim: 'Reported claim', analyst_inference: 'Analyst inference', rumor: 'Rumor', ai_hypothesis: 'AI-generated hypothesis',
};
const RELIABILITY: Record<string, string> = {
    A: 'Completely reliable', B: 'Usually reliable', C: 'Fairly reliable', D: 'Not usually reliable', E: 'Unreliable', F: 'Reliability cannot be judged',
};
const CREDIBILITY: Record<number, string> = {
    1: 'Confirmed by other sources', 2: 'Probably true', 3: 'Possibly true', 4: 'Doubtful', 5: 'Improbable', 6: 'Truth cannot be judged',
};

// P1-41: how a piece of evidence bears on a claim.
const RELATIONS: Record<string, string> = { supports: 'Supports', contradicts: 'Contradicts', context: 'Context' };

// P1-43: verification decisions, as a review history row reads.
const DECISIONS: Record<string, string> = {
    assign: 'Assigned', reassign: 'Reassigned', approve: 'Approved', request_changes: 'Changes requested', reject: 'Rejected',
    publish: 'Published', flag_for_rereview: 'Flagged for re-review',
};
// The choices a reviewer makes.
const DECISION_CHOICES: Record<string, string> = { approve: 'Approve', request_changes: 'Request changes', reject: 'Reject' };

export const decisionLabel = (decision: string): string => DECISIONS[decision] ?? decision;
export const decisionChoiceLabel = (decision: string): string => DECISION_CHOICES[decision] ?? decision;
// How long an item has waited since it was submitted, in whole minutes, hours or days (the queue's age).
export function ageLabel(iso: string | null, now: Date = new Date()): string {
    if (iso === null) {
        return '—';
    }
    const minutes = Math.max(0, Math.floor((now.getTime() - new Date(iso).getTime()) / 60000));
    if (minutes < 60) {
        return minutes === 1 ? '1 minute' : `${minutes} minutes`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        return hours === 1 ? '1 hour' : `${hours} hours`;
    }
    return `${Math.floor(hours / 24)} days`;
}
export const relationLabel = (relation: string): string => RELATIONS[relation] ?? relation;
export const typeLabel =(type: string): string => TYPES[type] ?? type;
export const statusLabel = (status: string): string => STATUSES[status] ?? status;
export const claimTypeLabel = (type: string): string => CLAIM_TYPES[type] ?? type;
export const reliabilityLabel = (grade: string): string => RELIABILITY[grade] ?? grade;
export const credibilityLabel = (grade: number): string => CREDIBILITY[grade] ?? String(grade);
// The compact Admiralty badge (ADR-0010), with its meaning for screen readers and tooltips.
export const ratingTitle = (reliability: string, credibility: number): string => `${reliability}${credibility}: ${reliabilityLabel(reliability)}; ${credibilityLabel(credibility).toLowerCase()}`;
