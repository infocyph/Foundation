<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Support;

trait HasConfigSection
{
    abstract protected function configSection(): string;

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get($this->configSection(), []);
        }

        return $this->config->get($this->configSection() . '.' . $key, $default);
    }
}
