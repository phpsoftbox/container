<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Diagnostics;

use Closure;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;

final readonly class ContainerValidator
{
    /**
     * @param list<string> $defaultIds
     * @param Closure(string): array<string, mixed> $graph
     */
    public function __construct(
        private array $defaultIds,
        private Closure $graph,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array{
     *     valid: bool,
     *     checked: list<string>,
     *     issues: list<array<string, mixed>>,
     * }
     */
    public function validate(array $ids = []): array
    {
        $checked = $this->normalizeIds($ids === [] ? $this->defaultIds : $ids);
        $issues  = [];
        $seen    = [];

        foreach ($checked as $id) {
            $this->collectNodeIssues(($this->graph)($id), [], $issues, $seen);
        }

        return [
            'valid'   => $issues === [],
            'checked' => $checked,
            'issues'  => $issues,
        ];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $path
     * @param list<array<string, mixed>> $issues
     * @param array<string, bool> $seen
     */
    private function collectNodeIssues(array $node, array $path, array &$issues, array &$seen): void
    {
        $id   = (string) ($node['id'] ?? '');
        $path = $this->appendPath($path, $id);

        if (($node['resolvable'] ?? false) === false) {
            $this->addIssue($issues, $seen, [
                'id'      => $id,
                'path'    => $path,
                'source'  => (string) ($node['source'] ?? 'unknown'),
                'reason'  => 'not-found',
                'message' => 'Entry is not resolvable: ' . $id,
            ]);
        }

        if (($node['cycle'] ?? false) === true) {
            $this->addIssue($issues, $seen, [
                'id'      => $id,
                'path'    => $path,
                'source'  => 'graph',
                'reason'  => 'cycle',
                'message' => 'Circular dependency detected: ' . implode(' -> ', $path),
            ]);
        }

        foreach ($node['issues'] ?? [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $reason = (string) ($issue['reason'] ?? 'unknown');
            $this->addIssue($issues, $seen, [
                ...$issue,
                'id'      => $id,
                'path'    => $path,
                'reason'  => $reason,
                'message' => $this->messageFor($id, $reason, $path),
            ]);
        }

        foreach ($node['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            if (($dependency['source'] ?? null) === 'unresolved') {
                $reason = (string) ($dependency['reason'] ?? 'unresolved');
                $this->addIssue($issues, $seen, [
                    ...$dependency,
                    'id'      => $id,
                    'path'    => $path,
                    'reason'  => $reason,
                    'message' => $this->messageFor($id, $reason, $path),
                ]);
            }

            if (is_array($dependency['node'] ?? null)) {
                $this->collectNodeIssues($dependency['node'], $path, $issues, $seen);
            }
        }
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function appendPath(array $path, string $id): array
    {
        if ($id === '') {
            return $path;
        }

        if (($path[count($path) - 1] ?? null) === $id) {
            return $path;
        }

        $path[] = $id;

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param array<string, bool> $seen
     * @param array<string, mixed> $issue
     */
    private function addIssue(array &$issues, array &$seen, array $issue): void
    {
        $key = $this->issueKey($issue);
        if (array_key_exists($key, $seen)) {
            return;
        }

        $seen[$key] = true;
        $issues[]   = $issue;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function issueKey(array $issue): string
    {
        $parts = [
            (string) ($issue['id'] ?? ''),
            (string) ($issue['reason'] ?? ''),
            (string) ($issue['relation'] ?? ''),
            (string) ($issue['method'] ?? ''),
            (string) ($issue['property'] ?? ''),
            (string) ($issue['parameter'] ?? ''),
            implode('>', is_array($issue['path'] ?? null) ? $issue['path'] : []),
        ];

        return implode('|', $parts);
    }

    /**
     * @param list<string> $path
     */
    private function messageFor(string $id, string $reason, array $path): string
    {
        return match ($reason) {
            'cycle'                   => 'Circular dependency detected: ' . implode(' -> ', $path),
            'required-parameter'      => 'Required parameter cannot be resolved for entry: ' . $id,
            'class-not-found'         => 'Target class was not found for entry: ' . $id,
            'class-reflection-failed' => 'Target class reflection failed for entry: ' . $id,
            'class-not-instantiable'  => 'Target class is not instantiable for entry: ' . $id,
            'method-not-found'        => 'Configured method was not found for entry: ' . $id,
            default                   => 'Container validation issue for entry ' . $id . ': ' . $reason,
        };
    }
}
