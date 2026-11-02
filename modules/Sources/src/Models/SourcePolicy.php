<?php

namespace Modules\Sources\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy decision on a source (P1-31a, docs/02 §3.4, docs/04 §2): append-only, a new version per decision. Seen
 * only through its source, so it follows the source's visibility. No mass assignment.
 *
 * @property int $id
 * @property int $source_id
 * @property int $version
 * @property int $proposed_by
 * @property string $decision
 * @property ?string $terms_url
 * @property ?string $terms_summary
 * @property ?CarbonInterface $terms_reviewed_on
 * @property string $robots_decision
 * @property ?string $license
 * @property ?string $allowed_cadence
 * @property ?int $rate_budget_per_hour
 * @property ?int $retention_days
 * @property bool $contains_personal_data
 * @property ?string $personal_data_basis
 * @property ?string $notes
 * @property int $decided_by
 * @property CarbonInterface $decided_at
 */
class SourcePolicy extends Model
{
    public const DECISIONS = ['approved', 'rejected', 'needs_info'];

    public const ROBOTS = ['allowed', 'disallowed', 'not_applicable', 'not_reviewed'];

    /** Methods that read pages the way a visitor does (docs/04 §1, ranks 5 and 6): only where robots allow them. */
    public const ROBOTS_BOUND_METHODS = ['http_fetch', 'browser'];

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'terms_reviewed_on' => 'date',
            'rate_budget_per_hour' => 'integer',
            'retention_days' => 'integer',
            'contains_personal_data' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }
}
