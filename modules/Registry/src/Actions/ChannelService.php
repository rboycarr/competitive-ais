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
use Modules\Platform\Database\UniqueViolation;
use Modules\Registry\Models\Channel;
use Modules\Registry\Models\Retailer;
use Modules\Registry\Support\Names;

/**
 * Sales channels (P1-23b, docs/03 Registry), maintained by taxonomy.manage holders: a name in the registry normal
 * form, unique ignoring case and accents, and a channel type. Name and type can change; a channel is removed only
 * while no retailer uses it (the foreign key refuses it too). Every change is audited (IDs and codes only).
 */
class ChannelService
{
    private const FIELDS = ['name', 'channel_type'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function create(Authenticatable $actor, string $name, string $type): Channel
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($name, $type);

        try {
            return $this->db->transaction(function () use ($actor, $attributes): Channel {
                $channel = new Channel;
                $channel->forceFill($attributes)->save();
                $this->audit->record('channel.created', $channel, ['channel_type' => $channel->channel_type], $this->actor($actor));

                return $channel;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw UniqueViolation::on($e, 'channels', 'channels_name_unique') ? $this->taken() : $e;
        }
    }

    public function update(Authenticatable $actor, Channel $channel, string $name, string $type): void
    {
        $this->assertMayManage($actor);
        $attributes = $this->attributes($name, $type);

        try {
            $this->db->transaction(function () use ($actor, $channel, $attributes): void {
                $locked = Channel::query()->whereKey($channel->id)->lockForUpdate()->first() ?? abort(404);
                $locked->forceFill($attributes);
                $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
                if ($changed === []) {
                    return;
                }
                $locked->save();
                $this->audit->record('channel.updated', $locked, ['fields' => $changed], $this->actor($actor));
                $channel->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw UniqueViolation::on($e, 'channels', 'channels_name_unique') ? $this->taken() : $e;
        }
    }

    public function remove(Authenticatable $actor, Channel $channel): void
    {
        $this->assertMayManage($actor);

        $this->db->transaction(function () use ($actor, $channel): void {
            $locked = Channel::query()->whereKey($channel->id)->lockForUpdate()->first();
            if ($locked === null) {
                return; // already removed
            }
            if (Retailer::query()->where('channel_id', $locked->id)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['channel' => 'Retailers use this channel; give them another channel first.']);
            }
            $locked->delete();
            $this->audit->record('channel.removed', $locked, ['channel_type' => $locked->channel_type], $this->actor($actor));
        });
    }

    /** @return array{name: string, channel_type: string} */
    private function attributes(string $name, string $type): array
    {
        $name = Names::normalize($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A channel name is 1 to 255 characters.']);
        }
        if (! in_array($type, Channel::TYPES, true)) {
            throw ValidationException::withMessages(['channel_type' => 'Unknown channel type.']);
        }

        return ['name' => $name, 'channel_type' => $type];
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A channel with this name exists.']);
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('taxonomy.manage')) {
            throw new AuthorizationException('You are not allowed to manage channels.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
