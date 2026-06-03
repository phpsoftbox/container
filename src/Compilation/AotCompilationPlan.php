<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

final readonly class AotCompilationPlan
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, list<mixed>> $decorators
     * @param array<string, string|null> $lazyEntries
     */
    public function __construct(
        private array $definitions,
        private array $decorators,
        private array $lazyEntries,
        private bool $autowiring,
        private bool $attributes,
    ) {
    }

    /**
     * @return array<string, array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }>
     */
    public function entries(): array
    {
        $entries  = [];
        $analyzer = $this->analyzer();

        foreach ($this->definitions as $id => $_) {
            $id           = (string) $id;
            $entries[$id] = $analyzer->analyze($id);
        }

        return $entries;
    }

    /**
     * @return array<string, array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }>
     */
    public function eligibleEntries(): array
    {
        $entries = [];

        foreach ($this->entries() as $id => $entry) {
            if (!$entry['eligible']) {
                continue;
            }

            $entries[$id] = $entry;
        }

        return $entries;
    }

    private function analyzer(): AotEligibilityAnalyzer
    {
        return new AotEligibilityAnalyzer(
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
        );
    }
}
