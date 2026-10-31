<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Platform\Classification;

/**
 * Writes one append-only, hashed audit row per sensitive action (P1-05, ADR-0006, docs/03 Platform).
 *
 * Must be called inside the caller's database transaction, so the audit row commits or rolls back with the
 * business change. Wrap stand-alone events (e.g. a failed login) in DB::transaction() explicitly.
 */
class AuditLogger
{
    private const MAX_STRING = 2000;

    private const MAX_LIST = 100;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditActions $actions,
        private readonly AuditHasher $hasher,
        private readonly Auth $auth,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $context  keys must be defined for the action; values are strings, integers,
     *                                         booleans, null, or lists of those
     * @param  ?Actor  $actor  defaults to the authenticated user, otherwise the system
     * @param  ?Classification  $classification  of the subject at event time; defaults to the subject's own
     *                                           `classification` attribute, otherwise internal
     * @return string the event's ULID
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?Actor $actor = null,
        ?Classification $classification = null,
    ): string {
        $connection = $this->db->connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException("AuditLogger::record() must run inside the caller's database transaction ([$action]).");
        }

        $actor ??= $this->currentActor();
        $row = [
            'ulid' => (string) Str::ulid(),
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $this->subjectType($subject),
            'subject_id' => $subject === null ? null : $this->subjectId($subject),
            'subject_ulid' => $this->subjectUlid($subject),
            'classification' => ($classification ?? $this->subjectClassification($subject))->value,
            'request_id' => $this->requestId(),
            'context' => $this->validContext($action, $context),
        ];
        $row['row_hash'] = $this->hasher->rowHash($row);
        $row['context'] = $row['context'] === null ? null : json_encode($row['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $id = $connection->table('audit_events')->insertGetId($row);

        if (($network = $this->network()) !== null) {
            $connection->table('audit_event_network')->insert(['audit_event_id' => $id] + $network + ['created_at' => $row['occurred_at']]);
        }

        return $row['ulid'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function validContext(string $action, array $context): ?array
    {
        $allowed = $this->actions->contextKeys($action);
        foreach ($context as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Context key [$key] is not allowed for audit action [$action].");
            }
            $values = is_array($value) ? $value : [$value];
            if (is_array($value) && (! array_is_list($value) || count($value) > self::MAX_LIST)) {
                throw new InvalidArgumentException("Context value [$key] for [$action] must be a list of at most ".self::MAX_LIST.' scalars.');
            }
            foreach ($values as $item) {
                // No floats or nested structures: the hash must survive the round trip through MySQL JSON.
                if (! (is_string($item) || is_int($item) || is_bool($item) || $item === null)
                    || (is_string($item) && mb_strlen($item) > self::MAX_STRING)) {
                    throw new InvalidArgumentException("Context value [$key] for [$action] must be a string (max ".self::MAX_STRING.' characters), integer, boolean or null.');
                }
            }
        }

        return $context === [] ? null : $context;
    }

    private function currentActor(): Actor
    {
        $id = $this->auth->guard()->id();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? Actor::user((int) $id) : Actor::system();
    }

    private function subjectType(?Model $subject): ?string
    {
        $type = $subject?->getMorphClass();
        if ($type !== null && strlen($type) > 100) {
            throw new InvalidArgumentException("Audit subject type [$type] is longer than 100 characters; register a morph alias.");
        }

        return $type;
    }

    private function subjectId(Model $subject): int
    {
        $key = $subject->getKey();
        if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
            throw new InvalidArgumentException('Audit subjects need a saved model with an integer key.');
        }

        return (int) $key;
    }

    private function subjectUlid(?Model $subject): ?string
    {
        $ulid = $subject?->getAttribute('ulid');

        return is_string($ulid) && Str::isUlid($ulid) ? strtoupper($ulid) : null;
    }

    private function subjectClassification(?Model $subject): Classification
    {
        $value = $subject?->getAttribute('classification');

        return match (true) {
            $value instanceof Classification => $value,
            is_string($value) => Classification::tryFrom($value) ?? throw new InvalidArgumentException("Unknown classification [$value]."),
            default => Classification::Internal,
        };
    }

    /** Set by the request-ID middleware (P1-08) through Laravel's Context. */
    private function requestId(): ?string
    {
        $id = Context::get('request_id');

        return is_string($id) && Str::isUlid($id) ? strtoupper($id) : null;
    }

    /**
     * IP address and user-agent hash, only while a routed HTTP request is handled (never in console runs).
     *
     * @return array{ip_address: ?string, user_agent_hash: ?string}|null
     */
    private function network(): ?array
    {
        if (! $this->container->bound('request')) {
            return null;
        }
        $request = $this->container->make('request');
        if ($request->route() === null) {
            return null;
        }

        $agent = $request->userAgent();

        return [
            'ip_address' => $request->ip(),
            'user_agent_hash' => is_string($agent) && $agent !== '' ? hash('sha256', $agent) : null,
        ];
    }
}
