<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Profiler;

use PhpSoftBox\Profiler\ProfilerCollectorInterface;
use PhpSoftBox\Profiler\ProfileTrace;

use function array_values;
use function max;
use function round;
use function uasort;

final class ContainerProfilerCollector implements ProfilerCollectorInterface
{
    /**
     * @var array<string, array{
     *     id: string,
     *     count: int,
     *     cached_hits: int,
     *     fresh: int,
     *     lazy: int,
     *     errors: int,
     *     total_ms: float,
     *     max_ms: float,
     *     last_exception_class: string|null,
     * }>
     */
    private array $entries = [];

    public function __construct(
        private readonly bool $traceResolves = false,
    ) {
    }

    public function key(): string
    {
        return 'container';
    }

    public function traceResolves(): bool
    {
        return $this->traceResolves;
    }

    public function recordResolve(
        string $id,
        float $durationMs,
        bool $cached,
        bool $fresh,
        bool $lazy,
        bool $failed = false,
        ?string $exceptionClass = null,
    ): void {
        if (!isset($this->entries[$id])) {
            $this->entries[$id] = [
                'id'                   => $id,
                'count'                => 0,
                'cached_hits'          => 0,
                'fresh'                => 0,
                'lazy'                 => 0,
                'errors'               => 0,
                'total_ms'             => 0.0,
                'max_ms'               => 0.0,
                'last_exception_class' => null,
            ];
        }

        $entry = &$this->entries[$id];

        $entry['count']++;
        $entry['total_ms'] = round($entry['total_ms'] + $durationMs, 3);
        $entry['max_ms']   = round(max($entry['max_ms'], $durationMs), 3);

        if ($cached) {
            $entry['cached_hits']++;
        }

        if ($fresh) {
            $entry['fresh']++;
        }

        if ($lazy) {
            $entry['lazy']++;
        }

        if ($failed) {
            $entry['errors']++;
            $entry['last_exception_class'] = $exceptionClass;
        }
    }

    public function collect(ProfileTrace $trace): array
    {
        $entries = $this->entries;
        $summary = [
            'count'       => 0,
            'cached_hits' => 0,
            'fresh'       => 0,
            'lazy'        => 0,
            'errors'      => 0,
            'total_ms'    => 0.0,
        ];

        foreach ($entries as $entry) {
            $summary['count'] += $entry['count'];
            $summary['cached_hits'] += $entry['cached_hits'];
            $summary['fresh'] += $entry['fresh'];
            $summary['lazy'] += $entry['lazy'];
            $summary['errors'] += $entry['errors'];
            $summary['total_ms'] = round($summary['total_ms'] + $entry['total_ms'], 3);
        }

        uasort($entries, static function (array $left, array $right): int {
            return $right['total_ms'] <=> $left['total_ms'];
        });

        return [
            'summary'  => $summary,
            'resolves' => array_values($entries),
        ];
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
