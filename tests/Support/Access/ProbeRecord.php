<?php

namespace Tests\Support\Access;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;

/**
 * A stand-in record for testing AccessScope before any module has classified, scoped records (P1-15). Lives on an
 * in-memory SQLite connection, so tests need no DDL on MySQL; the visibility filter only needs the record's own
 * tables, since grants are resolved in PHP first.
 *
 * @property int $id
 * @property string $label
 * @property string $classification
 */
class ProbeRecord extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public const CONNECTION = 'access_probe';

    public $timestamps = false;

    protected $connection = self::CONNECTION;

    protected $table = 'probe_records';

    protected $guarded = ['*'];

    public static function createSchema(): void
    {
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        /** @var Connection $connection */
        $connection = app('db')->connection(self::CONNECTION);
        $schema = $connection->getSchemaBuilder();
        $schema->create('probe_records', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('classification', 12);
        });
        foreach (['probe_record_competitor' => 'competitor_id', 'probe_record_market' => 'market_id'] as $pivot => $column) {
            $schema->create($pivot, function (Blueprint $table) use ($column) {
                $table->foreignId('probe_record_id')->constrained('probe_records')->cascadeOnDelete();
                $table->unsignedBigInteger($column);
                $table->primary(['probe_record_id', $column]);
            });
        }
    }

    /**
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     */
    public static function seed(string $label, string $classification, array $competitors, array $markets): self
    {
        $record = new self;
        $record->forceFill(['label' => $label, 'classification' => $classification])->save();
        foreach ($competitors as $id) {
            $record->competitors()->forceCreate(['competitor_id' => $id]);
        }
        foreach ($markets as $id) {
            $record->markets()->forceCreate(['market_id' => $id]);
        }

        return $record;
    }

    public function classification(): Classification
    {
        return Classification::from($this->classification);
    }

    public function competitorIds(): array
    {
        return array_values(array_map('intval', $this->competitors()->pluck('competitor_id')->all()));
    }

    public function marketIds(): array
    {
        return array_values(array_map('intval', $this->markets()->pluck('market_id')->all()));
    }

    public static function scopeRelations(): array
    {
        return ['competitors', 'markets'];
    }

    /** @return HasMany<ProbeCompetitorScope, $this> */
    public function competitors(): HasMany
    {
        return $this->hasMany(ProbeCompetitorScope::class);
    }

    /** @return HasMany<ProbeMarketScope, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(ProbeMarketScope::class);
    }
}
