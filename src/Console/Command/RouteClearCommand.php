<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Webrick\Constants\MatcherModeEnum;

final class RouteClearCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly RouteCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('route:clear')
            ->description('Remove the selected Webrick route matcher cache.')
            ->option(Option::value('matcher')->description(sprintf(
                'Matcher mode. Allowed values: %s; defaults to router.matcher.',
                implode('|', MatcherModeEnum::values()),
            )))
            ->option(Option::value('cache')->description(
                'Cache file or directory, for example: bootstrap/cache/routes/fused.php.',
            ))
            ->option(
                Option::value('aggressive')
                    ->type(ValueType::BOOLEAN)
                    ->default(false)
                    ->description('Also remove matcher-adjacent artifacts. Allowed values: true|false|1|0.'),
            );
    }

    protected function handle(): int
    {
        try {
            $matcher = $this->cache->matcher($this->options()->nullableString('matcher'));
            $cache = $this->cache->cachePath($this->options()->nullableString('cache'));
            $removed = $this->cache->clear(
                $matcher,
                $cache,
                $this->options()->bool('aggressive'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('route:clear failed: ' . $exception->getMessage());

            return 4;
        }

        if ($removed) {
            $this->io()->success('Route cache cleared: ' . $cache);
        } else {
            $this->io()->info('Nothing to clear at: ' . $cache);
        }

        return ExitCode::SUCCESS;
    }
}
