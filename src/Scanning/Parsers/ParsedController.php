<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing a parsed controller class.
 */
final class ParsedController
{
    /**
     * @param  string  $className  Short class name (e.g. "UserController")
     * @param  string  $fqcn  Fully-qualified class name
     * @param  ParsedMethod[]  $methods  All public, non-magic action methods
     */
    public function __construct(
        public readonly string $className,
        public readonly string $fqcn,
        public readonly array $methods,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'class_name' => $this->className,
            'fqcn' => $this->fqcn,
            'methods' => array_map(
                static fn (ParsedMethod $m) => $m->toArray(),
                $this->methods,
            ),
        ];
    }
}
