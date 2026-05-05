<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Models\Entity;

/**
 * Central hashing service used by all scanners and repositories.
 *
 * Centralising here means:
 *   • The algorithm (SHA-256) is defined in exactly one place.
 *   • Repositories never duplicate the "compare vs stored" check.
 *   • Scanners never call `hash()` directly.
 *
 * Static methods are intentional — this is pure, side-effect-free logic.
 */
final class FileHasher
{
    private const ALGORITHM = 'sha256';

    /**
     * Compute a hex digest for arbitrary string content (e.g. a file body,
     * or a pre-serialised collection fingerprint).
     */
    public static function content(string $content): string
    {
        return hash(self::ALGORITHM, $content);
    }

    /**
     * Compute a hex digest by reading a file from disk.
     *
     * Returns false when the file cannot be read (mirrors file_get_contents
     * behaviour so callers can use `=== false` checks).
     */
    public static function file(string $path): string|false
    {
        return hash_file(self::ALGORITHM, $path) ?: false;
    }

    /**
     * Decide whether an entity needs to be rescanned.
     *
     * Returns true (= needs rescan) when:
     *   • The entity has never been stored  ($entity === null)
     *   • The stored source_hash differs from the supplied hash
     *
     * The stored hash lives in metadata->source_hash.  A missing or empty
     * value is treated as "never scanned" so the entity is always processed
     * on first discovery.
     *
     * @param  Entity|null  $entity  The existing entity, or null if not yet stored
     * @param  string  $hash  The freshly computed hash of the current file
     */
    public static function entityNeedsRescan(?Entity $entity, string $hash): bool
    {
        if ($entity === null) {
            return true;
        }

        return ($entity->metadata?->get('source_hash') ?? '') !== $hash;
    }
}
