<?php

declare(strict_types=1);

namespace Arafat\Brain\Console\Commands;

use Arafat\Brain\AI\AppBrainService;
use Arafat\Brain\AI\Intent;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan app-brain:ask
 *
 * Accepts a natural-language question, runs it through AppBrainService
 * (keyword extraction → intent detection → context lookup → prompt build →
 * AI call), and prints a formatted answer to the console.
 *
 * Options
 * ───────
 *   --keyword=    Override the keyword used for context lookup.
 *   --json        Print the full AppBrainResponse as JSON instead of formatted output.
 *   --no-context  Skip context lookup and send the question directly to the AI.
 */
class AskCommand extends Command
{
    protected $signature = 'app-brain:ask
                            {question?          : The question to ask (prompted if omitted)}
                            {--keyword=         : Override the keyword used for context lookup}
                            {--json             : Output the full response as JSON}
                            {--no-context       : Skip context lookup; send the bare question to the AI}';

    protected $description = 'Ask a natural-language question about the application codebase.';

    public function __construct(private readonly AppBrainService $service)
    {
        parent::__construct();
    }

    // ── Entry point ───────────────────────────────────────────────────────────

    public function handle(): int
    {
        $question = $this->resolveQuestion();

        if (trim($question) === '') {
            $this->error('Question cannot be empty.');

            return self::FAILURE;
        }

        $keyword = $this->option('keyword') ?: null;

        $this->printBanner($question);

        try {
            $response = $this->service->ask($question, $keyword);
        } catch (AIException $e) {
            $this->newLine();
            $this->error("AI provider error [{$e->provider}]: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }

        // ── Output ────────────────────────────────────────────────────────────
        if ($this->option('json')) {
            $this->line($response->toJson());

            return self::SUCCESS;
        }

        $this->printMeta($response->intent, $response->keyword, $response->driver, $response->elapsedMs);
        $this->printContext($response->context->toArray());
        $this->printAnswer($response->answer);

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveQuestion(): string
    {
        $question = $this->argument('question');

        if ($question === null) {
            $question = $this->ask('<info>What would you like to know about the application?</info>');
        }

        return trim((string) $question);
    }

    private function printBanner(string $question): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>  Brain Ask</>');
        $this->line('  <fg=gray>'.str_repeat('─', 60).'</>');
        $this->line("  <options=bold>Q:</> {$question}");
        $this->line('  <fg=gray>'.str_repeat('─', 60).'</>');
        $this->newLine();
    }

    private function printMeta(Intent $intent, string $keyword, string $driver, float $elapsedMs): void
    {
        $this->table(
            ['Detected Intent', 'Keyword', 'AI Driver', 'Elapsed'],
            [[
                $intent->label(),
                $keyword,
                strtoupper($driver),
                number_format($elapsedMs, 1).' ms',
            ]],
        );
        $this->newLine();
    }

    private function printContext(array $context): void
    {
        if (empty($context)) {
            return;
        }

        $this->line('  <fg=yellow;options=bold>Context resolved:</>');

        foreach (['models', 'tables', 'routes', 'controllerMethods'] as $group) {
            $items = $context[$group] ?? [];
            if (!empty($items)) {
                $label = match ($group) {
                    'models' => 'Models',
                    'tables' => 'Tables',
                    'routes' => 'Routes',
                    'controllerMethods' => 'Controller Methods',
                };
                $names = array_column($items, 'name');
                $this->line("    <fg=green>{$label}:</> ".implode(', ', $names));
            }
        }

        $this->newLine();
    }

    private function printAnswer(string $answer): void
    {
        $this->line('  <fg=cyan;options=bold>Answer:</>');
        $this->line('  <fg=gray>'.str_repeat('─', 60).'</>');
        $this->newLine();

        // Indent each line for readability.
        foreach (explode("\n", $answer) as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('  <fg=gray>'.str_repeat('─', 60).'</>');
        $this->newLine();
    }
}
