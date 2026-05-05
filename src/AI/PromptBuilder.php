<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

/**
 * PromptBuilder
 *
 * Builds a strict, grounded prompt that forces the model to:
 *   • Use ONLY the provided context — never hallucinate external knowledge.
 *   • Return clean Markdown output with clickable links for every route/page.
 *   • Infer the application workflow from entity relationships.
 *   • Always include the full URL (app_url + URI) when mentioning a page.
 *
 * Usage
 * ─────
 *   $prompt = PromptBuilder::make('How do I create a category?', $contextArray)->build();
 */
final class PromptBuilder
{
    private string $question = '';

    /** @var array<string, mixed> */
    private array $context = [];

    // ── Fluent API ─────────────────────────────────────────────────────────────

    public static function make(string $question = '', array $context = []): self
    {
        return (new self)
            ->withQuestion($question)
            ->withContext($context);
    }

    public function withQuestion(string $question): self
    {
        $clone = clone $this;
        $clone->question = $question;

        return $clone;
    }

    /** @param  array<string, mixed>  $context */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    // ── Build ──────────────────────────────────────────────────────────────────

    public function build(): string
    {
        if (trim($this->question) === '') {
            throw new \InvalidArgumentException('PromptBuilder: question must not be empty.');
        }

        $contextBlock = $this->renderContextBlock();
        $appUrl = rtrim((string) ($this->context['app_url'] ?? ''), '/');

        return <<<PROMPT
        # ROLE

        You are an expert assistant for a Laravel web application.
        You have full access to the application's internal structure (routes, models, controllers,
        database tables) provided in the CONTEXT section below.

        Your responses must be:
        - **Accurate** — grounded exclusively in the CONTEXT. Never invent routes, models, or fields.
        - **Formatted in clean Markdown** — use headings, bullet lists, numbered steps, bold text,
          and inline code where helpful.
        - **Link-rich** — whenever you mention a page, route, or action, include a clickable Markdown
          link using the base URL from the context. Build links as:
          `[Label]({$appUrl}/the/route/uri)`
          For example, if the route URI is `/admin/categories/create` and app_url is `{$appUrl}`,
          the link is `[Create Category]({$appUrl}/admin/categories/create)`.
        - **Practical and complete** — when the user asks how to do something, provide:
          1. A brief overview of what the feature does.
          2. Step-by-step instructions with links to the relevant pages.
          3. Key fields / data involved (from models/tables in the context).
          4. Any prerequisites (e.g. "a category must exist before creating a product").

        ---

        # CONTEXT

        The following JSON document contains all known entities (models, database tables, routes,
        controller methods) and their relationships for this application.

        ```json
        {$contextBlock}
        ```

        ---

        # TASK

        {$this->question}

        ---

        # INSTRUCTIONS

        - Respond entirely in Markdown.
        - For every route or page you mention, format it as a clickable link:
          `[Descriptive Label]({$appUrl}/route/uri)`.
        - If multiple routes are relevant (list, create, edit, delete), include all of them with links.
        - List relevant models and their key fields when the question is about data or forms.
        - If a prerequisite exists (e.g. create X before Y), state it clearly at the top.
        - If information is not present in the CONTEXT, say "Not found in the application context."
          Do NOT invent or guess.

        ---

        # CONSTRAINTS

        - Use ONLY information from the CONTEXT block above.
        - Always use `{$appUrl}` as the base URL when forming links (do not invent or guess the domain).
        - Do not add general Laravel knowledge that is not reflected in the context.
        PROMPT;
    }

    // ── Internal helpers ───────────────────────────────────────────────────────

    /**
     * Serialise the context payload to indented JSON.
     * Returns a human-readable placeholder when context is empty.
     */
    private function renderContextBlock(): string
    {
        if (empty($this->context)) {
            return '{}  // No context provided — answers will be limited.';
        }

        return json_encode(
            $this->context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
