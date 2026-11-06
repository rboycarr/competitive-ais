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

export const relationLabel = (relation: string): string => RELATIONS[relation] ?? relation;
export const typeLabel =(type: string): string => TYPES[type] ?? type;
export const statusLabel = (status: string): string => STATUSES[status] ?? status;
export const claimTypeLabel = (type: string): string => CLAIM_TYPES[type] ?? type;
export const reliabilityLabel = (grade: string): string => RELIABILITY[grade] ?? grade;
export const credibilityLabel = (grade: number): string => CREDIBILITY[grade] ?? String(grade);
// The compact Admiralty badge (ADR-0010), with its meaning for screen readers and tooltips.
export const ratingTitle = (reliability: string, credibility: number): string => `${reliability}${credibility}: ${reliabilityLabel(reliability)}; ${credibilityLabel(credibility).toLowerCase()}`;
