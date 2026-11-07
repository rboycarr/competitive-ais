<?php

namespace Modules\Platform\Database;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Log;

/**
 * Alerts on long business transactions (P1-50, TASK-0010 #4, ADR-0006). An audit row that commits after its range
 * closed is a late commit, so transactions must stay well below the checkpoint lag window. Web requests and queued
 * jobs are cut off before that (docs/10 §5); a transaction that still commits after `audit.transaction_alert_seconds`
 * (a command, or a limit set too high) is logged critically, with the connection and duration only. Measures the
 * outermost transaction per connection, from its start to its commit.
 */
final class TransactionDurationMonitor
{
    /** @var array<string, float> start of the open outermost transaction, in Unix seconds, by connection */
    private array $started = [];

    public function __construct(private readonly Config $config) {}

    public function began(TransactionBeginning $event): void
    {
        if ($event->connection->transactionLevel() === 1) {
            $this->started[$event->connectionName] = (float) CarbonImmutable::now()->format('U.u');
        }
    }

    public function committed(TransactionCommitted $event): void
    {
        if ($event->connection->transactionLevel() !== 0 || ! isset($this->started[$event->connectionName])) {
            return;
        }
        $seconds = (float) CarbonImmutable::now()->format('U.u') - $this->started[$event->connectionName];
        unset($this->started[$event->connectionName]);

        $limit = (int) $this->config->get('audit.transaction_alert_seconds');
        if ($seconds > $limit) {
            Log::critical('Long database transaction committed', ['connection' => $event->connectionName, 'seconds' => round($seconds, 1), 'limit' => $limit]);
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        if ($event->connection->transactionLevel() === 0) {
            unset($this->started[$event->connectionName]);
        }
    }
}
