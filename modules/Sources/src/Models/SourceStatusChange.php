<?php

namespace Modules\Sources\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One suspension, reinstatement or retirement of a source (P1-31b, docs/04 §2): append-only, with a reason code and
 * a note. Like a policy, it has no visibility of its own and is read only through a visible source. No mass
 * assignment.
 *
 * @property int $id
 * @property int $source_id
 * @property string $action
 * @property string $from_status
 * @property string $to_status
 * @property string $reason
 * @property string $note
 * @property int $changed_by
 * @property CarbonInterface $changed_at
 */
class SourceStatusChange extends Model
{
    /**
     * Each lifecycle step: the statuses it starts from, the status it leads to, and the reasons it allows. The
     * database holds the same table (source_status_changes_transition_check).
     */
    public const ACTIONS = [
        'suspend' => ['from' => ['approved'], 'to' => 'suspended', 'reasons' => ['terms_changed', 'access_blocked', 'complaint', 'error_rate', 'other']],
        'reinstate' => ['from' => ['suspended'], 'to' => 'policy_review', 'reasons' => ['resolved', 'terms_changed', 'other']],
        'retire' => ['from' => ['proposed', 'policy_review', 'approved', 'suspended'], 'to' => 'retired', 'reasons' => ['no_longer_needed', 'terms_changed', 'complaint', 'other']],
    ];

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
