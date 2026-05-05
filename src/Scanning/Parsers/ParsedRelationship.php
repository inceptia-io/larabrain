<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing one Eloquent relationship method detected in a model.
 */
final class ParsedRelationship
{
    /**
     * @param  string  $method  The PHP method name on the model, e.g. "orders"
     * @param  string  $type  Eloquent relation type, e.g. "hasMany"
     * @param  string  $relatedClass  Short or FQCN of the related model, e.g. "Order"
     * @param  string|null  $foreignKey  Explicitly declared foreign key, if any
     * @param  string|null  $localKey  Explicitly declared local/owner key, if any
     */
    public function __construct(
        public readonly string $method,
        public readonly string $type,
        public readonly string $relatedClass,
        public readonly ?string $foreignKey = null,
        public readonly ?string $localKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'type' => $this->type,
            'related_class' => $this->relatedClass,
            'foreign_key' => $this->foreignKey,
            'local_key' => $this->localKey,
        ];
    }
}
