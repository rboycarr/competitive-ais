<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One assignment or decision in an item's verification (P1-43, docs/02 §3.6): append-only, bound to the item's version
 * and the SHA-256 of its content at that version (IntelligenceContent::hash). `reviewer_id` is whoever recorded it (a
 * director for an assignment); `assignee_id` whom an assignment names. Seen only through its item.
 *
 * @property int $id
 * @property string $ulid
 * @property int $intelligence_item_id
 * @property int $item_version
 * @property int $reviewer_id
 * @property ?int $assignee_id
 * @property string $decision
 * @property ?string $rationale plain text
 * @property string $content_hash
 * @property CarbonInterface $decided_at
 */
class VerificationReview extends Model
{
    /** The decisions written by P1-43; `publish` and `flag_for_rereview` come with P1-44 and P1-48. */
    public const ASSIGNMENTS = ['assign', 'reassign'];

    public const DECISIONS = ['approve', 'request_changes', 'reject'];

    /** Decisions that need a written reason (docs/03 state machine). */
    public const NEEDS_RATIONALE = ['reassign', 'request_changes', 'reject'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intelligence_item_id' => 'integer',
            'item_version' => 'integer',
            'reviewer_id' => 'integer',
            'assignee_id' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
