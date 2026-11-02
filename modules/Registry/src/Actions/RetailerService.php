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
use Modules\Registry\Models\Channel;
use Modules\Registry\Models\Market;
use Modules\Registry\Models\Retailer;
use Modules\Registry\Support\Names;

/**
 * Retailers (P1-23b, docs/03 Registry), maintained by taxonomy.manage holders:
 * - a retailer trades in one market, fixed at creation (a chain in two markets is two retailers), through one channel;
 * - names in the registry normal form, unique within the market ignoring case and accents;
 * - the website, when given, is an http(s) address (the competitor rule; the database checks the scheme too); it is
 *   shown as a link and never fetched;
 * - name, channel and website can change; an unused retailer can be removed; every change is audited (IDs only).
 */
class RetailerService
{
    private const FIELDS = ['name', 'channel_id', 'website'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, Market $market, string $name, int $channelId, ?string $website): Retailer
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($name, $website);

        try {
            return $this->db->transaction(function () use ($actor, $market, $channelId, $attributes): Retailer {
                $channel = $this->channel($channelId);
                $retailer = new Retailer;
                $retailer->forceFill(['ulid' => (string) Str::ulid(), 'market_id' => $market->id, 'channel_id' => $channel->id] + $attributes)->save();
                $this->audit->record('retailer.created', $retailer, ['market' => $market->ulid, 'channel_id' => $channel->id], $this->actor($actor));

                return $retailer;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function update(Authenticatable $actor, Retailer $retailer, string $name, int $channelId, ?string $website): void
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($name, $website);

        try {
            $this->db->transaction(function () use ($actor, $retailer, $channelId, $attributes): void {
                $locked = Retailer::query()->whereKey($retailer->id)->lockForUpdate()->first() ?? abort(404);
                $locked->forceFill(['channel_id' => $this->channel($channelId)->id] + $attributes);
                $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
                if ($changed === []) {
                    return;
                }
                $locked->save();
                $this->audit->record('retailer.updated', $locked, ['fields' => $changed], $this->actor($actor));
                $retailer->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function remove(Authenticatable $actor, Retailer $retailer): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $retailer): void {
            $locked = Retailer::query()->whereKey($retailer->id)->lockForUpdate()->first();
            if ($locked === null) {
                return; // already removed
            }
            $market = Market::query()->findOrFail($locked->market_id);
            $locked->delete();
            $this->audit->record('retailer.removed', $locked, ['market' => $market->ulid, 'channel_id' => $locked->channel_id], $this->actor($actor));
        });
    }

    /** Inside the write transaction: the channel cannot be removed until it commits. */
    private function channel(int $channelId): Channel
    {
        return Channel::query()->whereKey($channelId)->sharedLock()->first()
            ?? throw ValidationException::withMessages(['channel' => 'Choose a listed channel.']);
    }

    /** @return array{name: string, website: ?string} */
    private function attributes(string $name, ?string $website): array
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A retailer name is 1 to 255 characters.']);
        }
        $website = $website === null || trim($website) === '' ? null : trim($website);
        if ($website !== null && (strlen($website) > 2048 || ! CompetitorService::isWebAddress($website))) {
            throw ValidationException::withMessages(['website' => 'The website must be an http:// or https:// address.']);
        }

        return ['name' => $name, 'website' => $website];
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'This market already has a retailer with this name.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage retailers.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
