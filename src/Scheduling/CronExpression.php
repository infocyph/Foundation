<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final readonly class CronExpression
{
    /** @var list<string> */
    private array $parts;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (! is_array($parts) || count($parts) !== 5) {
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
            if (! $this->matchesPart($this->parts[$index], $values[$index], $this->range($index))) {
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
     * @param  array{int,int}  $range
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
            fn (string $segment): bool => $this->matchesSegment($segment, $value, $range),
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
            static fn (int $candidate): bool => $candidate >= $start
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
        if (! str_contains($base, '-')) {
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
        if (! str_contains($base, '-')) {
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
