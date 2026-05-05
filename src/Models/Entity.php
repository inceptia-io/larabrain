<?php

declare(strict_types=1);

namespace Arafat\Brain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $ulid
 * @property string $type
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property Collection|null $metadata
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<Relation> $outgoingRelations
 * @property-read \Illuminate\Database\Eloquent\Collection<Relation> $incomingRelations
 * @property-read \Illuminate\Database\Eloquent\Collection<Snapshot> $snapshots
 * @property-read Snapshot|null                                   $latestSnapshot
 */
class Entity extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'app_brain_entities';

    /**
     * The ULID column used by HasUlids.
     * We keep the primary key as the auto-increment `id` column and
     * use `ulid` purely as an external identifier.
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected $fillable = [
        'type',
        'key',
        'name',
        'description',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => AsCollection::class,
        'is_active' => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to active entities only.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to a specific entity type.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Relations where this entity is the source (outgoing edges).
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(Relation::class, 'source_entity_id');
    }

    /**
     * Relations where this entity is the target (incoming edges).
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(Relation::class, 'target_entity_id');
    }

    /**
     * All snapshots captured for this entity.
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'entity_id')->orderBy('version');
    }

    /**
     * The most recently created snapshot for this entity.
     */
    public function latestSnapshot(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'entity_id')->latestOfMany('version');
    }
}
