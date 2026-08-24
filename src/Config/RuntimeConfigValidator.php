<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Config\Internal\RuntimeCoreValidator;
use Infocyph\Foundation\Config\Internal\RuntimeLoggingValidator;
use Infocyph\Foundation\Config\Internal\RuntimeMessagingValidator;
use Infocyph\Foundation\Config\Internal\RuntimeMigrationValidator;
use Infocyph\Foundation\Config\Internal\RuntimeOperationsValidator;

final readonly class RuntimeConfigValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $core = new RuntimeCoreValidator($this->config);

        return [
            ...$core->topology(),
            ...$core->container(),
            ...new RuntimeLoggingValidator($this->config)->validate(),
            ...$core->notifications(),
            ...new RuntimeOperationsValidator($this->config)->validate(),
            ...new RuntimeMigrationValidator($this->config)->validate(),
            ...new RuntimeMessagingValidator($this->config)->validate(),
            ...$core->responses(),
        ];
    }
}
