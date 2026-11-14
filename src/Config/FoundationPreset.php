<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

/**
 * Application configuration preset contract.
 *
 * @api
 */
interface FoundationPreset
{
    /**
     * @return array<string, mixed>
     */
    public function config(): array;
}
