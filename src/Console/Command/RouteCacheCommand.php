<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Webrick\Constants\MatcherModeEnum;

final class RouteCacheCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly RouteCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('route:cache')
            ->description('Compile project routes for the selected Webrick matcher.')
            ->option(Option::value('matcher')->description(sprintf(
                'Matcher mode. Allowed values: %s; defaults to router.matcher.',
                implode('|', MatcherModeEnum::values()),
            )))
            ->option(Option::value('cache')->description(
                'Cache file or directory, for example: bootstrap/cache/routes/fused.php.',
            ))
            ->option(Option::value('routes')->description(
                'Comma-separated route files, for example: web.php,api.php.',
            ))
            ->option(
                Option::value('verbose')
                    ->type(ValueType::BOOLEAN)
                    ->default(false)
                    ->description('Show resolved command options. Allowed values: true|false|1|0.'),
            );
    }

    protected function handle(): int
    {
        try {
            $matcher = $this->cache->matcher($this->options()->nullableString('matcher'));
            $cache = $this->cache->cachePath($this->options()->nullableString('cache'));
            if ($this->options()->bool('verbose')) {
                $this->io()->info('Command: route:cache');
                $this->io()->details([
                    'matcher' => $matcher,
                    'cache' => $cache,
                    'routes' => $this->options()->nullableString('routes'),
                ]);
            }

            $sentinel = $this->cache->write(
                $matcher,
                $cache,
                $this->options()->nullableString('routes'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('route:cache failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Route cache ready at: ' . $sentinel);

        return ExitCode::SUCCESS;
    }
}
