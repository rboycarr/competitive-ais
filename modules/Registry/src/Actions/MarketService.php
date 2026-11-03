<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Database\UniqueViolation;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;

/**
 * Markets (P1-23a, docs/03 Registry), maintained by taxonomy.manage holders: an upper-case code that is unique and
 * fixed, a name, an ISO 3166 alpha-2 country code and a default currency from the ISO 4217 table. Never deleted.
 * Every change is audited (IDs and field names only).
 */
class MarketService
{
    /** Fields an edit may change; the code stays fixed. */
    private const FIELDS = ['name', 'country', 'default_currency'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    /** @param  array<string, ?string>  $data  code, name, country, currency */
    public function create(Authenticatable $actor, array $data): Market
    {
        $this->assertMayManage($actor);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'A code is 1 to 20 letters, digits, "-" or "_", starting with a letter or digit.']);
        }

        try {
            return $this->db->transaction(function () use ($actor, $code, $data): Market {
                $market = new Market;
                $market->forceFill(['ulid' => (string) Str::ulid(), 'code' => $code] + $this->attributes($data))->save();
                $this->audit->record('market.created', $market, [], $this->actor($actor));

                return $market;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw UniqueViolation::on($e, 'markets', 'markets_code_unique') ? ValidationException::withMessages(['code' => 'A market with this code exists.']) : $e;
        }
    }

    /** @param  array<string, ?string>  $data  name, country, currency */
    public function update(Authenticatable $actor, Market $market, array $data): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $market, $data): void {
            $locked = Market::query()->whereKey($market->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill($this->attributes($data));
            $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
            if ($changed === []) {
                return;
            }
            $locked->save();
            $this->audit->record('market.updated', $locked, ['fields' => $changed], $this->actor($actor));
            $market->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array{name: string, country: string, default_currency: string}
     */
    private function attributes(array $data): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A name is 1 to 255 characters.']);
        }
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw ValidationException::withMessages(['country' => 'Enter a two-letter ISO country code.']);
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (! Currency::query()->whereKey($currency)->exists()) {
            throw ValidationException::withMessages(['currency' => 'Choose a listed currency.']);
        }

        return ['name' => $name, 'country' => $country, 'default_currency' => $currency];
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage markets.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
