<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class TerminalIO implements CommandIO
{
    private bool $interactiveMode;

    public function __construct(
        private bool $quietMode = false,
        bool $interactive = true,
        private bool $jsonMode = false,
    ) {
        $this->interactiveMode = $interactive && self::isTty(STDIN);
    }

    public static function fromInput(ParsedInput $input): self
    {
        return new self(
            quietMode: $input->flag('quiet'),
            interactive: !$input->flag('no-interaction'),
            jsonMode: $input->flag('json'),
        );
    }

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        if ($choices === [] || array_any($choices, static fn(mixed $choice): bool => !is_string($choice) || $choice === '')) {
            throw new \InvalidArgumentException('Choice prompts require non-empty string choices.');
        }
        if ($default !== null && !in_array($default, $choices, true)) {
            throw new \InvalidArgumentException('Choice prompt default must be one of the choices.');
        }

        $this->assertInteractive();
        foreach (array_values($choices) as $index => $choice) {
            $this->writeln(sprintf('  %d) %s', $index + 1, $choice));
        }

        while (true) {
            $answer = $this->read($question, $default);
            if (in_array($answer, $choices, true)) {
                return $answer;
            }
            if (preg_match('/^\d+$/D', $answer) === 1) {
                $selected = $choices[(int) $answer - 1] ?? null;
                if (is_string($selected)) {
                    return $selected;
                }
            }
            $this->error('Select one of the listed choices.');
        }
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->assertInteractive();
        $suffix = $default ? ' [Y/n] ' : ' [y/N] ';

        while (true) {
            $answer = strtolower(trim($this->readRaw($question . $suffix)));
            if ($answer === '') {
                return $default;
            }
            if (in_array($answer, ['y', 'yes'], true)) {
                return true;
            }
            if (in_array($answer, ['n', 'no'], true)) {
                return false;
            }
            $this->error('Answer yes or no.');
        }
    }

    public function error(string $message): void
    {
        if ($this->jsonMode) {
            $encoded = json_encode(
                ['level' => 'error', 'message' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            fwrite(STDERR, $encoded . PHP_EOL);

            return;
        }

        fwrite(STDERR, $message . PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->semantic('INFO', $message);
    }

    public function interactive(): bool
    {
        return $this->interactiveMode;
    }

    public function json(mixed $value): void
    {
        if ($this->quietMode) {
            return;
        }

        $encoded = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        fwrite(STDOUT, $encoded . PHP_EOL);
    }

    public function machineReadable(): bool
    {
        return $this->jsonMode;
    }

    public function note(string $message): void
    {
        $this->semantic('NOTE', $message);
    }

    public function password(string $question): string
    {
        $this->assertInteractive();
        if (PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException('Secure hidden password input is unavailable on this Windows runtime.');
        }

        if (!$this->stty('-echo')) {
            throw new \RuntimeException('Unable to disable terminal echo for password input.');
        }

        try {
            return $this->readRaw($question . ' ');
        } finally {
            $this->stty('echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    public function quiet(): bool
    {
        return $this->quietMode;
    }

    public function read(string $question, ?string $default = null): string
    {
        $this->assertInteractive();
        $suffix = $default === null ? ' ' : sprintf(' [%s] ', $default);
        $value = $this->readRaw($question . $suffix);

        return $value === '' && $default !== null ? $default : $value;
    }

    public function success(string $message): void
    {
        $this->semantic('OK', $message);
    }

    public function table(array $headers, array $rows): void
    {
        if ($this->quietMode) {
            return;
        }
        if ($headers === [] || array_any($headers, static fn(mixed $header): bool => !is_string($header))) {
            throw new \InvalidArgumentException('Table headers must be a non-empty string list.');
        }
        if (array_any($rows, static fn(mixed $row): bool => !is_array($row) || count($row) !== count($headers))) {
            throw new \InvalidArgumentException('Every table row must match the header column count.');
        }

        if ($this->jsonMode) {
            $data = [];
            foreach ($rows as $row) {
                $data[] = array_combine($headers, array_map(self::scalarText(...), array_values($row)));
            }
            $this->json($data);

            return;
        }

        $rendered = [array_values($headers)];
        foreach ($rows as $row) {
            $rendered[] = array_map(self::scalarText(...), array_values($row));
        }

        $widths = array_fill(0, count($headers), 0);
        foreach ($rendered as $row) {
            foreach ($row as $index => $value) {
                $widths[$index] = max($widths[$index], self::displayWidth($value));
            }
        }

        foreach ($rendered as $index => $row) {
            $cells = [];
            foreach ($row as $column => $value) {
                $cells[] = self::padDisplay($value, $widths[$column]);
            }
            $this->writeln(implode('  ', $cells));
            if ($index === 0) {
                $this->writeln(implode('  ', array_map(static fn(int $width): string => str_repeat('-', $width), $widths)));
            }
        }
    }

    public function warning(string $message): void
    {
        $this->semantic('WARN', $message);
    }

    public function write(string $message): void
    {
        if (!$this->quietMode) {
            fwrite(STDOUT, $message);
        }
    }

    public function writeln(string $message = ''): void
    {
        if (!$this->quietMode) {
            fwrite(STDOUT, $message . PHP_EOL);
        }
    }

    private function assertInteractive(): void
    {
        if (!$this->interactiveMode) {
            throw new \RuntimeException('Interactive input is disabled or unavailable.');
        }
    }

    private static function displayWidth(string $value): int
    {
        $plain = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $value) ?? $value;
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($plain, 'UTF-8');
        }
        if (function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($plain);
            if (is_int($length)) {
                return $length;
            }
        }

        return strlen($plain);
    }

    private static function isTty(mixed $stream): bool
    {
        return is_resource($stream)
            && function_exists('stream_isatty')
            && stream_isatty($stream);
    }

    private static function padDisplay(string $value, int $width): string
    {
        return $value . str_repeat(' ', max(0, $width - self::displayWidth($value)));
    }

    private function readRaw(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $value = fgets(STDIN);
        if ($value === false) {
            throw new \RuntimeException('Unable to read terminal input.');
        }

        return rtrim($value, "\r\n");
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => throw new \InvalidArgumentException('Table cells must be scalar or null.'),
        };
    }

    private function semantic(string $role, string $message): void
    {
        if ($this->quietMode) {
            return;
        }
        if ($this->jsonMode) {
            $this->json(['level' => strtolower($role), 'message' => $message]);

            return;
        }

        $this->writeln(sprintf('[%s] %s', $role, $message));
    }

    private function stty(string $mode): bool
    {
        $process = @proc_open(['stty', $mode], [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }
}
