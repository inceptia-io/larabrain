<?php

/**
 * Laravel Brain — CodeIgniter 4 Route Definitions
 *
 * Include this file in your app/Config/Routes.php:
 *
 *   require ROOTPATH . 'vendor/inceptia-io/larabrain/routes/ci4-brain.php';
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
$routes->get('/brain', '\Arafat\Brain\CI4\Controllers\BrainController::chat', ['as' => 'brain.chat']);
$routes->post('/brain/ask', '\Arafat\Brain\CI4\Controllers\BrainController::ask', ['as' => 'brain.ask']);
