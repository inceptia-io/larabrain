<?php

declare(strict_types=1);

namespace Arafat\Brain\Facades;

use Arafat\Brain\Contracts\BrainInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Arafat\Brain\Brain
 */
class Brain extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BrainInterface::class;
    }
}
