<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing one public action method on a controller.
 */
final class ParsedMethod
{
    /**
     * @param  string  $name  PHP method name
     * @param  string[]  $params  Parameter variable names (without $)
     * @param  array<string,string[]>  $validationRules  field => [rule, rule, ...]
     * @param  string[]  $usedModels  FQCNs of Eloquent models referenced
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly array $validationRules,
        public readonly array $usedModels,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'params' => $this->params,
            'validation_rules' => $this->validationRules,
            'used_models' => $this->usedModels,
        ];
    }
}
