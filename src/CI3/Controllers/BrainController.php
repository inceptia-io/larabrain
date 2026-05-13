<?php

namespace Arafat\Brain\CI3\Controllers;

use Arafat\Brain\CI3\AppBrainCI3Service;
use Arafat\Brain\CI3\Services\BrainServices;
use Arafat\Brain\Exceptions\AIException;

/**
 * BrainController
 *
 * CodeIgniter 3 base controller for the Brain package.
 *
 * Users must create a thin wrapper in application/controllers/Brain.php:
 *
 *   <?php
 *   defined('BASEPATH') OR exit('No direct script access allowed');
 *   class Brain extends \Arafat\Brain\CI3\Controllers\BrainController {}
 *
 * Then register routes in application/config/routes.php:
 *
 *   require FCPATH . 'vendor/inceptia-io/larabrain/routes/ci3-brain.php';
 *
 * Endpoints
 * ─────────
 *   GET  /brain          — Standalone chat page
 *   POST /brain/ask      — JSON API for asking a question
 *   GET  /brain/widget   — Floating widget partial
 */
class BrainController extends \CI_Controller
{
    /** @var AppBrainCI3Service */
    private $brainService;

    public function __construct()
    {
        parent::__construct();

        $this->load->helper('url');

        $this->brainService = BrainServices::brain();
    }

    // ── GET /brain ─────────────────────────────────────────────────────────────

    public function chat(): void
    {
        $viewFile = BrainServices::viewPath() . 'chat.php';

        if (!file_exists($viewFile)) {
            show_error('Brain chat view not found: ' . $viewFile, 500);
            return;
        }

        include $viewFile;
    }

    // ── GET /brain/widget ──────────────────────────────────────────────────────

    /**
     * Render the floating widget partial.
     * Alternatively, include the view file directly in your layout:
     *
     *   <?php include FCPATH . 'vendor/inceptia-io/larabrain/resources/ci3-views/brain/widget.php'; ?>
     */
    public function widget(): void
    {
        $viewFile = BrainServices::viewPath() . 'widget.php';

        if (!file_exists($viewFile)) {
            show_error('Brain widget view not found: ' . $viewFile, 500);
            return;
        }

        include $viewFile;
    }

    // ── POST /brain/ask ────────────────────────────────────────────────────────

    public function ask(): void
    {
        $rawInput = (string) file_get_contents('php://input');
        $body     = json_decode($rawInput, true);

        $question = trim(
            (string) ($body['question'] ?? $this->input->post('question') ?? '')
        );

        if ($question === '') {
            $this->output
                ->set_status_header(422)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'The question field is required.']));
            return;
        }

        try {
            $result = $this->brainService->ask($question);

            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'answer'     => $result['answer'],
                    'intent'     => $result['intent'],
                    'driver'     => $result['driver'],
                    'elapsed_ms' => $result['elapsed_ms'],
                ]));
        } catch (AIException $e) {
            log_message('error', 'Brain AI error: ' . $e->getMessage());

            $this->output
                ->set_status_header(502)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'AI provider error. Please try again.']));
        } catch (\Throwable $e) {
            log_message('error', 'Brain unexpected error: ' . $e->getMessage());

            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'An unexpected error occurred.']));
        }
    }
}
