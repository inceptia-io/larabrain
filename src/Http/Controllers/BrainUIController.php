<?php

declare(strict_types=1);

namespace Arafat\Brain\Http\Controllers;

use Arafat\Brain\AI\AppBrainService;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class BrainUIController extends Controller
{
    public function __construct(private readonly AppBrainService $service) {}

    /**
     * Render the standalone chat page.
     */
    public function index(): View
    {
        /** @phpstan-ignore-next-line argument.type (view namespace syntax is valid but not recognized as view-string) */
        return view('brain::chat');
    }

    /**
     * Handle an AJAX ask request from the UI.
     */
    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $response = $this->service->ask(
                question: $validated['question'],
            );

            return response()->json([
                'answer'  => $response->answer,
                'intent'  => $response->intent->label(),
                'keyword' => $response->keyword,
                'driver'  => $response->driver,
                'elapsed' => round($response->elapsedMs),
            ]);
        } catch (AIException $e) {
            return response()->json([
                'error' => 'AI provider error: '.$e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Unexpected error: '.$e->getMessage(),
            ], 500);
        }
    }
}
