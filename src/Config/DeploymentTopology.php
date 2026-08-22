<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

enum DeploymentTopology: string
{
    case SINGLE_NODE = 'single_node';

    case DISTRIBUTED = 'distributed';

    public static function resolve(ConfigRepository $config): self
    {
        $value = $config->get('app.topology', self::SINGLE_NODE->value);

        return is_string($value)
            ? self::tryFrom(strtolower(trim($value))) ?? self::SINGLE_NODE
            : self::SINGLE_NODE;
    }
}
