<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

interface FoundationPreset
{
    /**
     * @return array<string, mixed>
     */
    public function config(): array;
}
