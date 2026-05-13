<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

/**
 * Intent
 *
 * Represents the detected intent of a user query.
 * Implemented as a value class (PHP 7.4+ compatible — no enum required).
 */
class Intent
{
    const EXPLAIN_WORKFLOW  = 'explain_workflow';
    const SHOW_ROUTES       = 'show_routes';
    const DESCRIBE_MODEL    = 'describe_model';
    const LIST_DEPENDENCIES = 'list_dependencies';
    const GENERAL           = 'general';

    /** @var string */
    public $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    // ── Named constructors (mimic enum cases) ─────────────────────────────────

    public static function ExplainWorkflow(): self  { return new self(self::EXPLAIN_WORKFLOW); }
    public static function ShowRoutes(): self        { return new self(self::SHOW_ROUTES); }
    public static function DescribeModel(): self     { return new self(self::DESCRIBE_MODEL); }
    public static function ListDependencies(): self  { return new self(self::LIST_DEPENDENCIES); }
    public static function General(): self           { return new self(self::GENERAL); }

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function from(string $value): self
    {
        $valid = [
            self::EXPLAIN_WORKFLOW,
            self::SHOW_ROUTES,
            self::DESCRIBE_MODEL,
            self::LIST_DEPENDENCIES,
            self::GENERAL,
        ];

        if (!in_array($value, $valid, true)) {
            throw new \InvalidArgumentException("'{$value}' is not a valid Intent value.");
        }

        return new self($value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable label for logging / debugging. */
    public function label(): string
    {
        $map = [
            self::EXPLAIN_WORKFLOW  => 'Explain Workflow',
            self::SHOW_ROUTES       => 'Show Routes',
            self::DESCRIBE_MODEL    => 'Describe Model',
            self::LIST_DEPENDENCIES => 'List Dependencies',
            self::GENERAL           => 'General',
        ];

        return $map[$this->value] ?? $this->value;
    }
}
