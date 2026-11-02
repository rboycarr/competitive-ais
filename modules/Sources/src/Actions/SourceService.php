<?php

namespace Modules\Sources\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Platform\Support\Features;
use Modules\Platform\Support\WebAddress;
use Modules\Sources\Models\Source;

/**
 * Proposing a source (P1-30, docs/04 §2, docs/03 Sources):
 * - needs source.propose; the source starts as `proposed` and is collected by nobody until a policy review approves
 *   it (P1-31, where the proposer cannot approve);
 * - the collection method must be `manual` while the `collection` flag is off (docs/04 acceptance criteria);
 *   automated methods also need a base URL;
 * - the base URL is an http(s) address on a domain name, without a user name or password; it is stored, never fetched;
 * - nobody classifies a source above what they can see, and the owner (an active holder of source.propose, checked
 *   under a lock on the user row; the proposer by default) must be able to see it too;
 * - audited in the same transaction, with codes only (no names or URLs in the audit context).
 */
class SourceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly UserDirectory $users,
        private readonly Gate $gate,
        private readonly Features $features,
    ) {}

    /**
     * @param  array{name: string, source_type: string, base_url?: ?string, method?: ?string, default_reliability?: ?string, owner?: ?string, classification: string, description?: ?string}  $data
     */
    public function propose(Authenticatable $actor, array $data): Source
    {
        if (! $this->gate->forUser($actor)->allows('source.propose')) {
            throw new AuthorizationException('You are not allowed to propose sources.');
        }
        $source = new Source;
        $source->forceFill($this->attributes($data) + [
            'ulid' => (string) Str::ulid(),
            'status' => 'proposed',
            'proposed_by' => $actor->getAuthIdentifier(),
        ]);
        if (! $this->access->allows($actor, $source)) {
            throw ValidationException::withMessages(['classification' => 'You cannot classify a source above what you can see.']);
        }

        return $this->db->transaction(function () use ($actor, $source, $data): Source {
            $source->owner_id = $this->ownerId($actor, $source, $data['owner'] ?? null);
            $source->save();
            $this->audit->record('source.proposed', $source, [
                'source_type' => $source->source_type,
                'method' => $source->method,
                'classification' => $source->classification->value,
            ], Actor::user((int) $actor->getAuthIdentifier()));

            return $source;
        });
    }

    /**
     * @param  array{name: string, source_type: string, base_url?: ?string, method?: ?string, default_reliability?: ?string, owner?: ?string, classification: string, description?: ?string}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $text = fn (?string $value): ?string => ($value = trim((string) $value)) === '' ? null : $value;
        $name = $text($data['name']) ?? throw ValidationException::withMessages(['name' => 'A name is required.']);
        $type = $text($data['source_type']);
        if (! in_array($type, Source::TYPES, true)) {
            throw ValidationException::withMessages(['source_type' => 'Unknown source type.']);
        }
        $method = $text($data['method'] ?? null) ?? 'manual';
        if (! in_array($method, Source::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Unknown collection method.']);
        }
        if ($method !== 'manual' && ! $this->features->enabled(Features::COLLECTION)) {
            throw ValidationException::withMessages(['method' => 'Only manual collection is available while automated collection is switched off.']);
        }
        $baseUrl = $text($data['base_url'] ?? null);
        $host = $baseUrl === null ? null : self::host($baseUrl);
        if ($method !== 'manual' && $baseUrl === null) {
            throw ValidationException::withMessages(['base_url' => 'Automated collection needs a base URL.']);
        }
        $reliability = $text($data['default_reliability'] ?? null) ?? 'F';
        if (! in_array($reliability, Source::RELIABILITIES, true)) {
            throw ValidationException::withMessages(['default_reliability' => 'Unknown reliability grade.']);
        }

        return [
            'name' => preg_replace('/\s+/u', ' ', $name),
            'source_type' => $type,
            'base_url' => $baseUrl,
            'host' => $host,
            'method' => $method,
            'default_reliability' => $reliability,
            'classification' => Classification::tryFrom($data['classification']) ?? throw ValidationException::withMessages(['classification' => 'Unknown classification.']),
            'description' => $text($data['description'] ?? null),
        ];
    }

    /**
     * The domain of a base URL, or a validation error saying what is wrong with it. One rule for the form request and
     * the service.
     *
     * @throws ValidationException
     */
    public static function host(string $baseUrl): string
    {
        $problem = match (true) {
            ! WebAddress::isValid($baseUrl) => 'The base URL must be an http:// or https:// address.',
            WebAddress::hasCredentials($baseUrl) => 'The base URL must not contain a user name or password.',
            WebAddress::domain($baseUrl) === null => 'The base URL must use a domain name, not an IP address or a local name.',
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['base_url' => $problem]);
        }

        return (string) WebAddress::domain($baseUrl);
    }

    /** Inside the write transaction: the proposer by default, otherwise an active source.propose holder now. */
    private function ownerId(Authenticatable $actor, Source $source, ?string $ulid): int
    {
        $ulid = trim((string) $ulid);
        if ($ulid === '') {
            return (int) $actor->getAuthIdentifier();
        }
        $owner = $this->users->lockedActiveHolder($ulid, 'source.propose')
            ?? throw ValidationException::withMessages(['owner' => 'The owner must be an active user who proposes sources.']);
        $account = $this->users->account($owner->id);
        if ($account === null || ! $this->access->allows($account, $source)) {
            throw ValidationException::withMessages(['owner' => 'The owner must be able to see a source with this classification.']);
        }

        return $owner->id;
    }
}
