<?php

declare(strict_types=1);

namespace Arafat\Brain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Snapshots are immutable records of an entity's state at a point in time.
 * They intentionally have no updated_at and no soft-deletes.
 *
 * @property int $id
 * @property int|null $entity_id
 * @property int $version
 * @property Collection $payload
 * @property string $hash SHA-256 hex of JSON payload
 * @property string|null $created_by
 * @property Collection|null $metadata
 * @property Carbon $created_at
 * @property-read Entity|null                        $entity
 */
class Snapshot extends Model
{
    use HasFactory;

    protected $table = 'app_brain_snapshots';

    /**
     * Snapshots have no updated_at column.
     */
    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'version',
        'payload',
        'hash',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'payload' => AsCollection::class,
        'metadata' => AsCollection::class,
        'version' => 'integer',
        'created_at' => 'datetime',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        // Automatically compute the SHA-256 hash before saving.
        static::creating(function (self $snapshot): void {
            if (empty($snapshot->hash)) {
                $snapshot->hash = hash(
                    'sha256',
                    is_string($snapshot->getRawOriginal('payload'))
                        ? $snapshot->getRawOriginal('payload')
                        : json_encode($snapshot->payload),
                );
            }
            $snapshot->created_at = $snapshot->freshTimestamp();
        });

        // Snapshots must never be updated.
        static::updating(function (): bool {
            return false;
        });
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to snapshots created by a specific actor.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeCreatedBy($query, string $actor)
    {
        return $query->where('created_by', $actor);
    }

    /**
     * Scope to global (entity-agnostic) snapshots.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('entity_id');
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The entity this snapshot belongs to.
     * Returns null for global snapshots.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
