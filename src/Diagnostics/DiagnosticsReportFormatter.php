<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Diagnostics;

use function array_key_exists;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function str_repeat;

final readonly class DiagnosticsReportFormatter
{
    /**
     * @param array<string, mixed> $graph
     */
    public function graph(array $graph): string
    {
        return implode("\n", [
            'Dependency graph: ' . (string) ($graph['id'] ?? '<unknown>'),
            ...$this->formatGraphNode($graph, 0),
        ]);
    }

    /**
     * @param array{
     *     valid: bool,
     *     checked: list<string>,
     *     issues: list<array<string, mixed>>,
     * } $report
     */
    public function validation(array $report): string
    {
        $lines = [
            'Validation: ' . (($report['valid'] ?? false) ? 'ok' : 'failed'),
            'Checked entries: ' . count($report['checked'] ?? []),
        ];

        $issues = $report['issues'] ?? [];
        if ($issues === []) {
            $lines[] = 'Issues: none';

            return implode("\n", $lines);
        }

        $lines[] = 'Issues:';
        foreach ($issues as $issue) {
            $lines[] = sprintf(
                '- [%s] %s',
                (string) ($issue['reason'] ?? 'unknown'),
                (string) ($issue['id'] ?? '<unknown>'),
            );

            if (is_string($issue['message'] ?? null) && $issue['message'] !== '') {
                $lines[] = '  message: ' . $issue['message'];
            }

            if (is_array($issue['path'] ?? null) && $issue['path'] !== []) {
                $lines[] = '  path: ' . implode(' -> ', $issue['path']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }> $plan
     */
    public function aotPlan(array $plan): string
    {
        $lines = ['AOT plan:'];

        foreach ($plan as $id => $entry) {
            $eligible = ($entry['eligible'] ?? false) ? 'eligible' : 'skipped';
            $kind     = $entry['kind'] ?? 'unknown';
            $line     = sprintf('- %s: %s (%s)', (string) $id, $eligible, (string) $kind);

            if (($entry['reasons'] ?? []) !== []) {
                $line .= ' reasons=' . implode(',', $entry['reasons']);
            }

            $lines[] = $line;
        }

        if (count($lines) === 1) {
            $lines[] = '- no explicit definitions';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private function formatGraphNode(array $node, int $depth): array
    {
        $lines    = [];
        $id       = (string) ($node['id'] ?? '<unknown>');
        $metadata = [
            'source=' . (string) ($node['source'] ?? 'unknown'),
            'resolvable=' . $this->formatBool($node['resolvable'] ?? false),
        ];

        if (array_key_exists('target', $node)) {
            $metadata[] = 'target=' . (string) $node['target'];
        }

        if (($node['lazy'] ?? false) === true) {
            $metadata[] = 'lazy=yes';
        }

        if (($node['decorators'] ?? 0) > 0) {
            $metadata[] = 'decorators=' . (string) $node['decorators'];
        }

        if (($node['cycle'] ?? false) === true) {
            $metadata[] = 'cycle=yes';
        }

        $lines[] = $this->indent($depth) . '- ' . $id . ' [' . implode(', ', $metadata) . ']';

        foreach ($node['issues'] ?? [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $lines[] = $this->indent($depth + 1) . '- issue: ' . (string) ($issue['reason'] ?? 'unknown');
        }

        foreach ($node['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            $lines[] = $this->formatDependency($dependency, $depth + 1);

            if (is_array($dependency['node'] ?? null)) {
                foreach ($this->formatGraphNode($dependency['node'], $depth + 2) as $nestedLine) {
                    $lines[] = $nestedLine;
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function formatDependency(array $dependency, int $depth): string
    {
        $parts = [
            (string) ($dependency['relation'] ?? 'dependency'),
            (string) ($dependency['id'] ?? '<dynamic>'),
        ];

        foreach (['source', 'parameter', 'property', 'method', 'reason'] as $key) {
            if (!array_key_exists($key, $dependency)) {
                continue;
            }

            $parts[] = $key . '=' . (string) $dependency[$key];
        }

        if (array_key_exists('resolvable', $dependency)) {
            $parts[] = 'resolvable=' . $this->formatBool($dependency['resolvable']);
        }

        return $this->indent($depth) . '- ' . implode(' ', $parts);
    }

    private function indent(int $depth): string
    {
        return str_repeat('  ', $depth);
    }

    private function formatBool(mixed $value): string
    {
        if (!is_bool($value)) {
            return (string) $value;
        }

        return $value ? 'yes' : 'no';
    }
}
