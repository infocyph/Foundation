<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Webrick\Constants\MatcherModeEnum;

abstract class AbstractRouteCacheCommand extends AbstractFoundationCommand
{
    public function __construct(protected readonly RouteCacheManager $cache) {}

    final protected static function defineRouteCacheOperation(
        CommandDefinition $command,
        string $name,
        string $description,
    ): void {
        $command
            ->name($name)
            ->description($description)
            ->option(Option::value('matcher')->description(sprintf(
                'Matcher mode. Allowed values: %s; defaults to router.matcher.',
                implode('|', MatcherModeEnum::values()),
            )))
            ->option(Option::value('cache')->description(
                'Cache file or directory, for example: bootstrap/cache/routes/fused.php.',
            ));
    }
}
