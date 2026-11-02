<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;
use Modules\Registry\Support\Names;

/**
 * Currencies (P1-23b, PO decision O29), maintained by taxonomy.manage holders:
 * - a currency is added with a three-letter upper-case code (the key, fixed), a name and its minor units (0–4);
 * - the name can change; the minor units only while no market uses the currency, so recorded amounts keep their
 *   meaning (the currency row is locked first, and a market referring to it takes a shared lock on that row through
 *   its foreign key, so none can appear in between);
 * - never deleted; every change is audited with the code and field names only (no subject: the key is not an ID).
 */
class CurrencyService
{
    /** Fields an edit may change; the code stays fixed. */
    private const FIELDS = ['name', 'minor_units'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, string $code, string $name, int $minorUnits): Currency
    {
        $this->assertMayManage($actor);
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'A currency code is three letters (ISO 4217).']);
        }
        $attributes = ['name' => $this->name($name), 'minor_units' => $this->minorUnits($minorUnits)];

        try {
            return $this->db->transaction(function () use ($actor, $code, $attributes): Currency {
                $currency = new Currency;
                $currency->forceFill(['code' => $code] + $attributes)->save();
                $this->audit->record('currency.created', null, ['currency' => strtolower($code), 'minor_units' => $attributes['minor_units']], $this->actor($actor));

                return $currency;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This currency is already listed.']);
        }
    }

    public function update(Authenticatable $actor, Currency $currency, string $name, int $minorUnits): void
    {
        $this->assertMayManage($actor);
        $attributes = ['name' => $this->name($name), 'minor_units' => $this->minorUnits($minorUnits)];

        $this->db->transaction(function () use ($actor, $currency, $attributes): void {
            $locked = Currency::query()->whereKey($currency->code)->lockForUpdate()->firstOrFail();
            $locked->forceFill($attributes);
            $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
            if ($changed === []) {
                return;
            }
            if ($locked->isDirty('minor_units') && Market::query()->where('default_currency', $locked->code)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['minor_units' => 'Markets use this currency, so its decimal places stay as they are.']);
            }
            $locked->save();
            $this->audit->record('currency.updated', null, ['currency' => strtolower($locked->code), 'fields' => $changed], $this->actor($actor));
            $currency->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function name(string $name): string
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'A currency name is 1 to 100 characters.']);
        }

        return $name;
    }

    private function minorUnits(int $minorUnits): int
    {
        if ($minorUnits < 0 || $minorUnits > 4) {
            throw ValidationException::withMessages(['minor_units' => 'Decimal places are 0 to 4.']);
        }

        return $minorUnits;
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage currencies.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
