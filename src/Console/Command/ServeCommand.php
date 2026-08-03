<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;
use Infocyph\Foundation\Application\Application;

final class ServeCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('serve')
            ->description('Run the PHP development server for this application.')
            ->option(Option::value('host')->default('127.0.0.1')->description(
                'Host or IP address to bind. Example: 127.0.0.1.',
            ))
            ->option(Option::value('port')->type(ValueType::INTEGER)->default(8000)->description(
                'TCP port to bind. Allowed values: 1-65535.',
            ))
            ->option(Option::flag('dry-run')->description(
                'Validate the server configuration and print it without starting a process.',
            ));
    }

    protected function handle(): int
    {
        try {
            $endpoint = $this->endpoint(
                $this->options()->string('host'),
                $this->options()->int('port'),
            );
            $publicPath = $this->application->publicPath();
            $this->assertPublicPath($publicPath);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->io()->error('serve failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->note(sprintf('Development server: http://%s', $endpoint));
        $this->io()->details(['document_root' => $publicPath]);

        if ($this->options()->bool('dry-run')) {
            return ExitCode::SUCCESS;
        }

        if (!function_exists('proc_open')) {
            $this->io()->error('serve failed: proc_open is unavailable in this PHP runtime.');

            return ExitCode::FAILURE;
        }

        try {
            $process = proc_open(
                [PHP_BINARY, '-S', $endpoint, '-t', $publicPath],
                [STDIN, STDOUT, STDERR],
                $pipes,
                $this->application->paths()->base(),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('serve failed: ' . $exception->getMessage());

            return ExitCode::FAILURE;
        }
        if (!is_resource($process)) {
            $this->io()->error('serve failed: unable to start the PHP development server.');

            return ExitCode::FAILURE;
        }

        $status = proc_close($process);

        return $status === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function assertPublicPath(string $path): void
    {
        if (!is_dir($path)) {
            throw new \RuntimeException(sprintf('Public directory does not exist: %s', $path));
        }
        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('Public directory is not readable: %s', $path));
        }
    }

    private function endpoint(string $host, int $port): string
    {
        $host = trim($host);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535.');
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new \InvalidArgumentException('Host cannot be empty.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return sprintf('[%s]:%d', $host, $port);
        }
        if (
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new \InvalidArgumentException('Host must be a valid IP address or hostname.');
        }

        return sprintf('%s:%d', $host, $port);
    }
}
