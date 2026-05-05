<?php

declare(strict_types=1);

namespace Arafat\Brain\Facades;

use Arafat\Brain\AI\AppBrainResponse;
use Arafat\Brain\AI\AppBrainService;
use Illuminate\Support\Facades\Facade;

/**
 * AppBrain Facade
 *
 * Shortcut to AppBrainService — the full pipeline entry-point.
 *
 * @method static AppBrainResponse ask(string $question, ?string $keyword = null)
 *
 * @see AppBrainService
 */
class AppBrain extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AppBrainService::class;
    }
}
