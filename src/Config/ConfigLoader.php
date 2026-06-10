<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ConfigLoader
{
    /**
     * @param array<string, mixed> $input
     */
    public function load(array $input = []): ConfigRepository
    {
        $normalized = $this->normalizeInput($input);
        $defaults = $this->defaults();
        $fileConfig = $this->loadConfigDirectory($normalized);

        $repository = new ConfigRepository($defaults);
        $repository->merge($fileConfig);
        $repository->merge($normalized);

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return ConfigMerger::merge(
            FoundationDefaults::all(),
            AuthDefaults::all(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function loadConfigDirectory(array $input): array
    {
        $basePath = $input['app']['base_path'] ?? null;
        if (!is_string($basePath) || $basePath === '') {
            return [];
        }

        $configDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($configDir)) {
            return [];
        }

        $loaded = [];
        $files = glob($configDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $values = require $file;

            if (!is_string($name) || $name === '' || !is_array($values)) {
                continue;
            }

            $loaded[$name] = $values;
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (isset($input['base_path']) && !isset($input['app']['base_path'])) {
            $input['app']['base_path'] = $input['base_path'];
        }

        if (isset($input['env']) && !isset($input['app']['env'])) {
            $input['app']['env'] = $input['env'];
        }

        if (isset($input['debug']) && !isset($input['app']['debug'])) {
            $input['app']['debug'] = $input['debug'];
        }

        return $input;
    }
}
