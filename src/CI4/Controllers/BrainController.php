<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4\Controllers;

use Arafat\Brain\CI4\AppBrainCI4Service;
use Arafat\Brain\CI4\Services\BrainServices;
use Arafat\Brain\Exceptions\AIException;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * BrainController
 *
 * CodeIgniter 4 controller providing the same endpoints as the Laravel version:
 *
 *   GET  /brain          — Standalone chat page
 *   POST /brain/ask      — JSON API for asking a question
 *
 * Register in app/Config/Routes.php:
 *
 *   $routes->get('/brain', '\Arafat\Brain\CI4\Controllers\BrainController::chat');
 *   $routes->post('/brain/ask', '\Arafat\Brain\CI4\Controllers\BrainController::ask');
 *
 * Or use the helper route file:
 *
 *   require ROOTPATH . 'vendor/inceptia-io/larabrain/routes/ci4-brain.php';
 */
class BrainController extends Controller
{
    /** @var list<string> */
    protected $helpers = ['url', 'form'];

    private AppBrainCI4Service $brainService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger,
    ): void {
        parent::initController($request, $response, $logger);

        $this->brainService = BrainServices::brain();
    }

    // ── GET /brain ─────────────────────────────────────────────────────────────

    public function chat(): ResponseInterface
    {
        return view(\Arafat\Brain\CI4\Services\BrainServices::viewPath() . 'chat.php');
    }

    // ── GET /brain/widget ──────────────────────────────────────────────────────

    /**
     * Render the floating widget partial.
     * Alternatively, include the view file directly in your layout:
     *
     *   <?php include \Arafat\Brain\CI4\Services\BrainServices::viewPath() . 'widget.php'; ?>
     */
    public function widget(): ResponseInterface
    {
        return view(\Arafat\Brain\CI4\Services\BrainServices::viewPath() . 'widget.php');
    }

    // ── POST /brain/ask ────────────────────────────────────────────────────────

    public function ask(): ResponseInterface
    {
        /** @var IncomingRequest $request */
        $request = $this->request;

        $body     = $request->getJSON(true);
        $question = trim((string) ($body['question'] ?? $request->getPost('question') ?? ''));

        if ($question === '') {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(['error' => 'The question field is required.']);
        }

        try {
            $result = $this->brainService->ask($question);

            return $this->response->setJSON([
                'answer'     => $result['answer'],
                'intent'     => $result['intent'],
                'driver'     => $result['driver'],
                'elapsed_ms' => $result['elapsed_ms'],
            ]);
        } catch (AIException $e) {
            log_message('error', 'Brain AI error: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(502)
                ->setJSON(['error' => 'AI provider error. Please try again.']);
        } catch (\Throwable $e) {
            log_message('error', 'Brain unexpected error: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'An unexpected error occurred.']);
        }
    }
}
