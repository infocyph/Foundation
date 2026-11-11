<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

final class BenchmarkSupport
{
    /**
     * @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float}
     * @throws \InvalidArgumentException
     */
    public static function measure(
        callable $operation,
        int $operations,
        int $repetitions,
        int $warmup,
    ): array {
        if ($operations < 1 || $repetitions < 1 || $warmup < 0) {
            throw new \InvalidArgumentException('Benchmark operations and repetitions must be positive and warmup non-negative.');
        }

        $samples = [];

        for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
            for ($iteration = 0; $iteration < $warmup; ++$iteration) {
                $operation();
            }

            $started = hrtime(true);
            for ($iteration = 0; $iteration < $operations; ++$iteration) {
                $operation();
            }
            $samples[] = max(1, hrtime(true) - $started) / $operations;
        }

        sort($samples, SORT_NUMERIC);
        $median = $samples[intdiv(count($samples), 2)];
        $minimum = $samples[0];
        $maximum = $samples[count($samples) - 1];

        return [
            'median_ns' => round($median, 2),
            'min_ns' => round($minimum, 2),
            'max_ns' => round($maximum, 2),
            'spread_percent' => round((($maximum - $minimum) / max(1.0, $median)) * 100, 2),
        ];
    }

    public static function ratio(float $numerator, float $denominator): float
    {
        return round($numerator / max(1.0, $denominator), 4);
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * @return array{median_ns:float,ops_per_second:float,min_ns:float,max_ns:float,samples_ns:list<float>}
     * @throws \InvalidArgumentException
     */
    public static function throughputMeasure(
        callable $operation,
        int $operations,
        int $repetitions,
        int $warmup,
    ): array {
        if ($operations < 1 || $repetitions < 1 || $warmup < 0) {
            throw new \InvalidArgumentException('Benchmark operations and repetitions must be positive and warmup non-negative.');
        }

        $samples = [];

        for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
            for ($iteration = 0; $iteration < $warmup; ++$iteration) {
                $operation();
            }

            $started = hrtime(true);
            for ($iteration = 0; $iteration < $operations; ++$iteration) {
                $operation();
            }
            $samples[] = (hrtime(true) - $started) / $operations;
        }

        sort($samples, SORT_NUMERIC);
        $median = $samples[intdiv(count($samples), 2)];

        return [
            'median_ns' => round($median, 2),
            'ops_per_second' => round(1_000_000_000.0 / $median, 2),
            'min_ns' => round($samples[0], 2),
            'max_ns' => round($samples[count($samples) - 1], 2),
            'samples_ns' => array_map(static fn(float $sample): float => round($sample, 2), $samples),
        ];
    }
}
