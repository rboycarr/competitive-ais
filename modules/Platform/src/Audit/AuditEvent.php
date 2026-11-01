<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;

/**
 * Read model of the audit trail (P1-16). Rows are written only by AuditLogger and never change (append-only; the app
 * account has no UPDATE or DELETE), so saving or deleting through this model is refused. A row is classified like
 * its subject at event time and is filtered by AccessScope like any other record: it has no competitor or market
 * scopes. Network data (IP address) stays in audit_event_network and is not part of this model.
 *
 * @property int $id
 * @property string $ulid
 * @property CarbonImmutable $occurred_at
 * @property string $actor_type
 * @property ?int $actor_id
 * @property string $action
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?string $subject_ulid
 * @property Classification $classification
 * @property ?string $request_id
 * @property ?array<string, mixed> $context
 */
class AuditEvent extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public $timestamps = false;

    protected $table = 'audit_events';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('Audit events are written by AuditLogger only and never changed.');
        static::saving($refuse);
        static::deleting($refuse);
    }

    public function classification(): Classification
    {
        return $this->classification;
    }

    public function competitorIds(): array
    {
        return [];
    }

    public function marketIds(): array
    {
        return [];
    }

    public static function scopeRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'classification' => Classification::class,
            'context' => 'array',
        ];
    }
}
