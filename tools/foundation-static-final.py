from pathlib import Path

# CLI preflight: keep hot metadata path tiny and move rendering branches to private helpers.
Path('src/Command/CliPreflight.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Composer\InstalledVersions;

final readonly class CliPreflight
{
    public function __construct(private CommandCatalog $catalog = new CommandCatalog()) {}

    /**
     * Handle metadata-only invocations without constructing Foundation Application.
     * Returns an exit code when handled, otherwise null to continue to command execution.
     *
     * @param list<string> $argv
     */
    public function handle(array $argv, CommandIO $io): ?int
    {
        $input = ParsedInput::fromArgv($argv);
        if ($input->flag('version') || in_array('-V', $input->raw, true)) {
            $io->writeln('Foundation ' . $this->version());

            return 0;
        }

        return match ($input->command) {
            '', 'list' => $this->list($io),
            'help' => $this->help($input, $io),
            'completion' => $this->completion($io),
            default => null,
        };
    }

    private function completion(CommandIO $io): int
    {
        foreach (array_keys($this->catalog->all()) as $name) {
            $io->writeln($name);
        }

        return 0;
    }

    private function help(ParsedInput $input, CommandIO $io): int
    {
        $name = $input->argument(0);
        $definition = $name === null ? null : $this->catalog->find($name);
        if ($definition === null) {
            $this->renderList($io);

            return $name === null ? 0 : 1;
        }

        $io->writeln($definition->name . ' - ' . $definition->description);
        $io->writeln('Runtime: ' . $definition->runtime->value);
        if ($definition->capabilities !== []) {
            $io->writeln('Capabilities: ' . implode(', ', $definition->capabilities));
        }

        return 0;
    }

    private function list(CommandIO $io): int
    {
        $this->renderList($io);

        return 0;
    }

    private function renderList(CommandIO $io): void
    {
        $groups = [];
        foreach ($this->catalog->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        foreach ($groups as $group => $definitions) {
            $io->writeln($group . ':');
            foreach ($definitions as $definition) {
                $io->writeln(sprintf('  %-28s %s', $definition->name, $definition->description));
            }
            $io->writeln();
        }
    }

    private function version(): string
    {
        return InstalledVersions::isInstalled('infocyph/foundation')
            ? (InstalledVersions::getPrettyVersion('infocyph/foundation') ?? 'dev')
            : 'dev';
    }
}
''')

Path('src/Command/ParsedInput.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class ParsedInput
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|bool|list<string>> $options
     * @param list<string> $raw
     */
    public function __construct(
        public string $command,
        public array $arguments,
        public array $options,
        public array $raw,
    ) {}

    /** @param list<string> $argv */
    public static function fromArgv(array $argv): self
    {
        $tokens = array_slice($argv, 1);
        $command = '';
        $arguments = [];
        $options = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '--') {
                $arguments = [...$arguments, ...array_slice($tokens, $index + 1)];
                break;
            }
            if (str_starts_with($token, '--')) {
                $index = self::consumeLongOption($tokens, $index, $options);
                continue;
            }
            if ($command === '' && !str_starts_with($token, '-')) {
                $command = $token;
                continue;
            }
            $arguments[] = $token;
        }

        return new self($command, $arguments, $options, $tokens);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function flag(string $name): bool
    {
        $value = $this->options[$name] ?? false;

        return $value === true || $value === '1' || $value === 'true';
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $last = end($value);

            return is_string($last) ? $last : $default;
        }

        return $default;
    }

    /** @param array<string, string|bool|list<string>> $options */
    private static function addOption(array &$options, string $name, string|bool $value): void
    {
        if ($name === '') {
            return;
        }
        if (!array_key_exists($name, $options)) {
            $options[$name] = $value;
            return;
        }

        $existing = $options[$name];
        $options[$name] = is_array($existing)
            ? [...$existing, (string) $value]
            : [(string) $existing, (string) $value];
    }

    /**
     * @param list<string> $tokens
     * @param array<string, string|bool|list<string>> $options
     */
    private static function consumeLongOption(array $tokens, int $index, array &$options): int
    {
        $body = substr($tokens[$index], 2);
        if ($body === '') {
            return $index;
        }
        if (str_contains($body, '=')) {
            [$name, $value] = explode('=', $body, 2);
            self::addOption($options, $name, $value);

            return $index;
        }

        $next = $tokens[$index + 1] ?? null;
        if (is_string($next) && $next !== '' && $next[0] !== '-') {
            self::addOption($options, $body, $next);

            return $index + 1;
        }

        self::addOption($options, $body, true);

        return $index;
    }
}
''')

Path('src/Process/ProcessRunner.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final class ProcessRunner
{
    /** @param list<string>|string $command Prefer an argument list to bypass the shell. */
    public function run(array|string $command, ?ProcessOptions $options = null): ProcessResult
    {
        $options ??= new ProcessOptions();
        $this->assertCommand($command);

        if ($options->interactive) {
            return $this->runInteractive($command, $options);
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $options->cwd,
            $this->environment($options),
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        try {
            return $this->capture($process, $pipes, $options);
        } catch (\Throwable $exception) {
            $this->closePipes($pipes);
            proc_terminate($process);
            proc_close($process);

            throw $exception;
        }
    }

    /** @param list<string>|string $command */
    private function assertCommand(array|string $command): void
    {
        if (!is_array($command)) {
            return;
        }
        if ($command === [] || array_any($command, static fn(string $part): bool => $part === '')) {
            throw new \InvalidArgumentException('Process command arguments must be non-empty strings.');
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function capture($process, array $pipes, ProcessOptions $options): ProcessResult
    {
        fwrite($pipes[0], $options->input ?? '');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = hrtime(true);
        $timedOut = false;

        while ($this->running($process)) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if ($this->timeoutReached($startedAt, $options->timeoutSeconds)) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return new ProcessResult($timedOut ? 124 : $exitCode, $stdout, $stderr, $timedOut);
    }

    /** @param array<int, mixed> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function environment(ProcessOptions $options): ?array
    {
        if ($options->environment === []) {
            return null;
        }

        $environment = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key)) {
                $environment[$key] = $value;
            }
        }
        foreach ($options->environment as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment;
    }

    /** @param resource $process */
    private function running($process): bool
    {
        return proc_get_status($process)['running'];
    }

    /** @param list<string>|string $command */
    private function runInteractive(array|string $command, ProcessOptions $options): ProcessResult
    {
        $process = proc_open(
            $command,
            [STDIN, STDOUT, STDERR],
            $pipes,
            $options->cwd,
            $this->environment($options),
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive process.');
        }

        $startedAt = hrtime(true);
        $timedOut = false;
        while ($this->running($process)) {
            if ($this->timeoutReached($startedAt, $options->timeoutSeconds)) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        $exitCode = proc_close($process);

        return new ProcessResult($timedOut ? 124 : $exitCode, timedOut: $timedOut);
    }

    private function timeoutReached(int $startedAt, ?float $timeoutSeconds): bool
    {
        return $timeoutSeconds !== null
            && (hrtime(true) - $startedAt) / 1_000_000_000 >= $timeoutSeconds;
    }
}
''')

Path('src/Scheduling/CronExpression.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final readonly class CronExpression
{
    /** @var list<string> */
    private array $parts;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (!is_array($parts) || count($parts) !== 5) {
            throw new \InvalidArgumentException('Cron expressions must contain five fields.');
        }
        $this->parts = $parts;
        foreach ($this->parts as $index => $part) {
            $this->validate($part, $this->range($index));
        }
    }

    public function expression(): string
    {
        return implode(' ', $this->parts);
    }

    public function matches(\DateTimeInterface $dateTime): bool
    {
        $values = [
            (int) $dateTime->format('i'),
            (int) $dateTime->format('G'),
            (int) $dateTime->format('j'),
            (int) $dateTime->format('n'),
            (int) $dateTime->format('w'),
        ];
        foreach ([0, 1, 3] as $index) {
            if (!$this->matchesPart($this->parts[$index], $values[$index], $this->range($index))) {
                return false;
            }
        }

        $dayOfMonth = $this->matchesPart($this->parts[2], $values[2], $this->range(2));
        $dayOfWeek = $this->matchesPart($this->parts[4], $values[4], $this->range(4));
        if ($this->parts[2] === '*' || $this->parts[4] === '*') {
            return $dayOfMonth && $dayOfWeek;
        }

        return $dayOfMonth || $dayOfWeek;
    }

    /**
     * @param array{int,int} $range
     * @return list<int>
     */
    private function candidateValues(int $value, array $range): array
    {
        return $range[1] === 7 && $value === 0 ? [0, 7] : [$value];
    }

    /** @param array{int,int} $range */
    private function matchesPart(string $part, int $value, array $range): bool
    {
        return array_any(
            explode(',', $part),
            fn(string $segment): bool => $this->matchesSegment($segment, $value, $range),
        );
    }

    /** @param array{int,int} $range */
    private function matchesSegment(string $segment, int $value, array $range): bool
    {
        $parts = explode('/', $segment, 2);
        $base = $parts[0];
        $step = isset($parts[1]) ? (int) $parts[1] : 1;
        if ($step < 1) {
            return false;
        }
        if ($base === '*') {
            return ($value - $range[0]) % $step === 0;
        }

        [$start, $end] = $this->segmentRange($base);

        return array_any(
            $this->candidateValues($value, $range),
            static fn(int $candidate): bool => $candidate >= $start
                && $candidate <= $end
                && ($candidate - $start) % $step === 0,
        );
    }

    /** @return array{int,int} */
    private function range(int $index): array
    {
        return match ($index) {
            0 => [0, 59],
            1 => [0, 23],
            2 => [1, 31],
            3 => [1, 12],
            4 => [0, 7],
            default => throw new \InvalidArgumentException('Cron field index must be between zero and four.'),
        };
    }

    /** @return array{int,int} */
    private function segmentRange(string $base): array
    {
        if (!str_contains($base, '-')) {
            $value = (int) $base;
            return [$value, $value];
        }
        [$start, $end] = explode('-', $base, 2);

        return [(int) $start, (int) $end];
    }

    /** @param array{int,int} $range */
    private function validate(string $part, array $range): void
    {
        foreach (explode(',', $part) as $segment) {
            $this->validateSegment($segment, $range);
        }
    }

    /** @param array{int,int} $range */
    private function validateSegment(string $segment, array $range): void
    {
        if (preg_match('/^(\*|\d+(?:-\d+)?)(?:\/\d+)?$/', $segment) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid cron segment "%s".', $segment));
        }

        $parts = explode('/', $segment, 2);
        $base = $parts[0];
        $step = $parts[1] ?? null;
        $this->validateStep($step);
        $this->validateRangeOrder($base);
        $this->validateValues($segment, $range);
    }

    private function validateRangeOrder(string $base): void
    {
        if (!str_contains($base, '-')) {
            return;
        }
        [$start, $end] = $this->segmentRange($base);
        if ($start > $end) {
            throw new \InvalidArgumentException('Cron ranges must be ascending.');
        }
    }

    private function validateStep(?string $step): void
    {
        if ($step !== null && (int) $step < 1) {
            throw new \InvalidArgumentException('Cron steps must be positive.');
        }
    }

    /** @param array{int,int} $range */
    private function validateValues(string $segment, array $range): void
    {
        foreach (preg_split('/[-\/]/', $segment) ?: [] as $value) {
            if ($value === '*') {
                continue;
            }
            $numeric = (int) $value;
            if ($numeric < $range[0] || $numeric > $range[1]) {
                throw new \InvalidArgumentException(sprintf('Cron value "%s" is out of range.', $value));
            }
        }
    }
}
''')

Path('src/Scheduling/ScheduledCommand.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final class ScheduledCommand
{
    /** @var list<string> */
    private array $arguments = [];
    private CronExpression $cron;
    private ?string $key = null;
    private ?int $memoryLimitMegabytes = null;
    private bool $onOneServer = false;
    private float $overlapLeaseSeconds = 300.0;
    private float $overlapWaitSeconds = 0.0;
    private ?float $timeoutSeconds = null;
    private \DateTimeZone $timezone;
    private bool $withoutOverlap = false;

    public function __construct(private readonly string $command)
    {
        if ($command === '') {
            throw new \InvalidArgumentException('Scheduled command cannot be empty.');
        }
        $this->cron = new CronExpression('* * * * *');
        $this->timezone = new \DateTimeZone(date_default_timezone_get());
    }

    /** @param array<string, mixed> $data */
    public static function fromManifest(array $data): self
    {
        $command = new self(self::stringValue($data['command'] ?? null, ''));
        $command->arguments(self::stringList($data['arguments'] ?? null));
        $command->cron(self::stringValue($data['cron'] ?? null, '* * * * *'));
        $command->timezone(self::stringValue($data['timezone'] ?? null, date_default_timezone_get()));

        $key = self::nullableString($data['key'] ?? null);
        if ($key !== null) {
            $command->key($key);
        }

        $lease = self::floatValue($data['overlap_lease_seconds'] ?? null, 300.0);
        $wait = self::floatValue($data['overlap_wait_seconds'] ?? null, 0.0);
        if (($data['without_overlap'] ?? false) === true) {
            $command->withoutOverlap(true, $lease, $wait);
        }
        if (($data['on_one_server'] ?? false) === true) {
            $command->onOneServer(true, $lease, $wait);
        }

        $timeout = self::nullableFloat($data['timeout_seconds'] ?? null);
        if ($timeout !== null) {
            $command->timeout($timeout);
        }
        $memory = self::nullableInt($data['memory_limit_megabytes'] ?? null);
        if ($memory !== null) {
            $command->memoryLimit($memory);
        }

        return $command;
    }

    /** @param list<string> $arguments */
    public function arguments(array $arguments): self
    {
        if (array_any($arguments, static fn(string $argument): bool => $argument === '')) {
            throw new \InvalidArgumentException('Scheduled command arguments must be non-empty strings.');
        }
        $this->arguments = $arguments;

        return $this;
    }

    public function command(): string { return $this->command; }

    /** @return list<string> */
    public function commandArguments(): array { return $this->arguments; }

    public function cron(string $expression): self
    {
        $this->cron = new CronExpression($expression);
        return $this;
    }

    public function dailyAt(string $time): self
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new \InvalidArgumentException('Daily schedule times must use HH:MM format.');
        }
        [$hour, $minute] = explode(':', $time);
        return $this->cron((int) $minute . ' ' . (int) $hour . ' * * *');
    }

    public function due(\DateTimeInterface $now): bool
    {
        return $this->cron->matches(new \DateTimeImmutable('@' . $now->getTimestamp())->setTimezone($this->timezone));
    }

    public function everyMinute(): self { return $this->cron('* * * * *'); }
    public function hourly(): self { return $this->cron('0 * * * *'); }

    public function identity(): string
    {
        return $this->key ?? hash('sha256', json_encode([
            $this->command,
            $this->arguments,
            $this->cron->expression(),
            $this->timezone->getName(),
        ], JSON_THROW_ON_ERROR));
    }

    public function key(string $key): self
    {
        if ($key === '' || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Schedule keys must be safe identifiers of at most 128 bytes.');
        }
        $this->key = $key;
        return $this;
    }

    public function memoryLimit(int $megabytes): self
    {
        if ($megabytes < 1) {
            throw new \InvalidArgumentException('Schedule memory limit must be positive.');
        }
        $this->memoryLimitMegabytes = $megabytes;
        return $this;
    }

    public function memoryLimitMegabytes(): ?int { return $this->memoryLimitMegabytes; }

    public function onOneServer(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0): self
    {
        $this->assertLockTiming($leaseSeconds, $waitSeconds);
        $this->onOneServer = $enabled;
        $this->overlapLeaseSeconds = $leaseSeconds;
        $this->overlapWaitSeconds = $waitSeconds;
        return $this;
    }

    public function overlapLeaseSeconds(): float { return $this->overlapLeaseSeconds; }
    public function overlapWaitSeconds(): float { return $this->overlapWaitSeconds; }
    public function preventsOverlap(): bool { return $this->withoutOverlap; }
    public function requiresSingleServer(): bool { return $this->onOneServer; }

    public function timeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('Schedule timeout must be positive.');
        }
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    public function timeoutSeconds(): ?float { return $this->timeoutSeconds; }

    public function timezone(string|\DateTimeZone $timezone): self
    {
        $this->timezone = is_string($timezone) ? new \DateTimeZone($timezone) : $timezone;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        return [
            'key' => $this->key,
            'command' => $this->command,
            'arguments' => $this->arguments,
            'cron' => $this->cron->expression(),
            'timezone' => $this->timezone->getName(),
            'without_overlap' => $this->withoutOverlap,
            'on_one_server' => $this->onOneServer,
            'overlap_wait_seconds' => $this->overlapWaitSeconds,
            'overlap_lease_seconds' => $this->overlapLeaseSeconds,
            'timeout_seconds' => $this->timeoutSeconds,
            'memory_limit_megabytes' => $this->memoryLimitMegabytes,
        ];
    }

    public function withoutOverlap(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0): self
    {
        $this->assertLockTiming($leaseSeconds, $waitSeconds);
        $this->withoutOverlap = $enabled;
        $this->overlapLeaseSeconds = $leaseSeconds;
        $this->overlapWaitSeconds = $waitSeconds;
        return $this;
    }

    private function assertLockTiming(float $leaseSeconds, float $waitSeconds): void
    {
        if (!is_finite($leaseSeconds) || !is_finite($waitSeconds) || $leaseSeconds <= 0 || $waitSeconds < 0) {
            throw new \InvalidArgumentException('Schedule lock lease must be positive and wait cannot be negative.');
        }
    }

    private static function floatValue(mixed $value, float $default): float
    {
        return self::nullableFloat($value) ?? $default;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if (is_float($value)) return $value;
        if (is_int($value)) return (float) $value;
        if (is_string($value) && is_numeric($value)) return (float) $value;
        return null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && ctype_digit($value)) return (int) $value;
        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $list = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \UnexpectedValueException('Schedule manifest arguments must be non-empty strings.');
            }
            $list[] = $entry;
        }
        return $list;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
''')

# Small typed normalizations.
p = Path('src/Routing/RouteCacheManager.php')
s = p.read_text().replace(
    '/** @param array<string, mixed> $options @return array<string, mixed> */',
    '/**\n     * @param array<string, mixed> $options\n     * @return array<string, mixed>\n     */',
)
p.write_text(s)

p = Path('src/Scheduling/ScheduleManager.php')
s = p.read_text().replace(
    '$schedule->add(ScheduledCommand::fromManifest($entry));',
    '$schedule->add(ScheduledCommand::fromManifest($this->manifestEntry($entry)));',
)
insert = r'''
    /** @param array<array-key, mixed> $entry @return array<string, mixed> */
    private function manifestEntry(array $entry): array
    {
        $normalized = [];
        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

'''
s = s.replace('    private function path(string $path): string\n', insert + '    private function path(string $path): string\n')
p.write_text(s)

Path('src/Worker/WorkerManager.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;

final readonly class WorkerManager
{
    public function __construct(private Application $application) {}

    /** @return array<string, class-string<WorkerProvider>> */
    public function all(string $routes = 'routes/workers.php'): array
    {
        $path = $this->path($routes);
        if (!is_file($path)) {
            return [];
        }

        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new \UnexpectedValueException(sprintf('Worker route file "%s" must return a class map.', $path));
        }

        $workers = [];
        foreach ($definitions as $name => $provider) {
            if (!is_string($name)
                || $name === ''
                || !is_string($provider)
                || $provider === ''
                || !is_a($provider, WorkerProvider::class, true)
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Worker definitions must map non-empty names to %s implementations.',
                    WorkerProvider::class,
                ));
            }
            /** @var class-string<WorkerProvider> $provider */
            $workers[$name] = $provider;
        }

        return $workers;
    }

    public function run(string $name, string $routes = 'routes/workers.php'): ?int
    {
        $providerClass = $this->all($routes)[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Worker "%s" is not defined.',
            $name,
        ));
        $provider = $this->application->boot()->make($providerClass);
        $runtime = new WorkerRuntime($this->application);
        $leaseSeconds = max(30.0, $this->leaseSeconds());
        $lock = $this->application->make(CacheLayerFactory::class)->lock();
        $handle = $lock->acquire('foundation:worker:' . $name, 0.0, $leaseSeconds);
        if ($handle === null) {
            return null;
        }

        try {
            return $provider->run($runtime);
        } finally {
            $lock->release($handle);
        }
    }

    private function leaseSeconds(): float
    {
        $value = $this->application->config()->get('worker.lock_lease_seconds', 300.0);
        if (is_float($value)) return $value;
        if (is_int($value)) return (float) $value;
        if (is_string($value) && is_numeric($value)) return (float) $value;
        return 300.0;
    }

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }
}
''')

# Remove temporary snapshot/helper from final source commit.
Path('.github/workflows/foundation-static-snapshot.yml').unlink(missing_ok=True)
Path('tools/foundation-static-final.py').unlink(missing_ok=True)
