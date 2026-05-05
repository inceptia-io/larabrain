<?php

declare(strict_types=1);

namespace Arafat\Brain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $source_entity_id
 * @property int $target_entity_id
 * @property string $type
 * @property float $weight
 * @property Collection|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Entity                             $source
 * @property-read Entity                             $target
 */
class Relation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'app_brain_relations';

    protected $fillable = [
        'source_entity_id',
        'target_entity_id',
        'type',
        'weight',
        'metadata',
    ];

    protected $casts = [
        'metadata' => AsCollection::class,
        'weight' => 'float',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to relations of a specific type.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to relations above a minimum weight threshold.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithMinWeight($query, float $minimum)
    {
        return $query->where('weight', '>=', $minimum);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The entity that is the source (origin) of this relation.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    /**
     * The entity that is the target (destination) of this relation.
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
