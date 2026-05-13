<?php

/**
 * Brain — CodeIgniter 3 Route Definitions
 *
 * Add this to the bottom of application/config/routes.php:
 *
 *   require FCPATH . 'vendor/inceptia-io/larabrain/routes/ci3-brain.php';
 *
 * Prerequisites
 * ─────────────
 * 1. Create application/controllers/Brain.php:
 *
 *   <?php
 *   defined('BASEPATH') OR exit('No direct script access allowed');
 *   class Brain extends \Arafat\Brain\CI3\Controllers\BrainController {}
 *
 * 2. Enable Composer autoloading in application/config/config.php:
 *
 *   $config['composer_autoload'] = FCPATH . 'vendor/autoload.php';
 *
 * @var array $route
 */

$route['brain']['GET']        = 'Brain/chat';
$route['brain/ask']['POST']   = 'Brain/ask';
$route['brain/widget']['GET'] = 'Brain/widget';
