<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

/**
 * Intent
 *
 * The detected intent of a user query, resolved by AppBrainService
 * before the prompt is assembled.  The intent is stored on the
 * AppBrainResponse so callers can inspect how the query was classified.
 */
enum Intent: string
{
    /**
     * "How does X work?" / "Explain the X flow" / "Walk me through X".
     * The AI is asked to narrate the end-to-end workflow.
     */
    case ExplainWorkflow = 'explain_workflow';

    /**
     * "What routes does X have?" / "List endpoints for X" / "Show routes".
     * Focus is on HTTP surface — routes and controller entry-points.
     */
    case ShowRoutes = 'show_routes';

    /**
     * "Describe the X model" / "What fields does X have?" / "X schema".
     * Focus is on the Eloquent model and its backing table.
     */
    case DescribeModel = 'describe_model';

    /**
     * "What are the dependencies of X?" / "What calls X?" / "What uses X?".
     * Focus is on identifying relationships and coupling.
     */
    case ListDependencies = 'list_dependencies';

    /**
     * Catch-all for queries that don't match a more specific intent.
     * The full structured prompt is still generated and sent to the AI.
     */
    case General = 'general';

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable label for logging / debugging. */
    public function label(): string
    {
        return match ($this) {
            self::ExplainWorkflow => 'Explain Workflow',
            self::ShowRoutes => 'Show Routes',
            self::DescribeModel => 'Describe Model',
            self::ListDependencies => 'List Dependencies',
            self::General => 'General',
        };
    }
}
