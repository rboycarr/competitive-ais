<?php

namespace Modules\Platform\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The audit trail does not match its checkpoints (docs/10 §5: any mismatch is critical). Alerting listens to this.
 */
final class AuditVerificationFailed
{
    use Dispatchable;

    /**
     * @param  list<array{type: string, severity: string, detail: string}>  $issues
     */
    public function __construct(public readonly array $issues) {}
}
