<?php

namespace EasyAudit\Core\Scan\Util;

/**
 * Cycle detection for directed graphs using iterative 3-color DFS.
 *
 * Input: associative adjacency list `['A' => ['B', 'C'], 'B' => ['A']]`.
 * Output: array of cycles, each cycle itself an array of node ids, canonicalized so
 * the lexicographically smallest node appears first.
 */
final class Graph
{
    /**
     * @param  array<string, array<int, string>> $adjacency
     * @return array<int, array<int, string>>
     */
    public static function detectCycles(array $adjacency): array
    {
        $nodes = array_unique(array_merge(
            array_keys($adjacency),
            ...array_values(array_map('array_values', $adjacency))
        ));
        sort($nodes);

        $color = array_fill_keys($nodes, 'white');
        $cycles = [];
        $seen = [];

        foreach ($nodes as $node) {
            if ($color[$node] !== 'white') {
                continue;
            }
            self::visit($node, $adjacency, $color, [], $cycles, $seen);
        }

        return $cycles;
    }

    /**
     * @param array<string, array<int, string>> $adjacency
     * @param array<string, string>             $color
     * @param array<int, string>                $stack
     * @param array<int, array<int, string>>    $cycles
     * @param array<string, bool>               $seen
     */
    private static function visit(
        string $node,
        array $adjacency,
        array &$color,
        array $stack,
        array &$cycles,
        array &$seen
    ): void {
        $color[$node] = 'gray';
        $stack[] = $node;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if (!isset($color[$neighbor])) {
                $color[$neighbor] = 'white';
            }
            if ($color[$neighbor] === 'gray') {
                $index = array_search($neighbor, $stack, true);
                if ($index !== false) {
                    $cycle = array_slice($stack, (int)$index);
                    $canonical = self::canonicalize($cycle);
                    $key = implode('->', $canonical);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $cycles[] = $canonical;
                    }
                }
            } elseif ($color[$neighbor] === 'white') {
                self::visit($neighbor, $adjacency, $color, $stack, $cycles, $seen);
            }
        }

        $color[$node] = 'black';
    }

    /**
     * Rotate the cycle so the lexicographically smallest node is first.
     *
     * @param  array<int, string> $cycle
     * @return array<int, string>
     */
    private static function canonicalize(array $cycle): array
    {
        if (empty($cycle)) {
            return $cycle;
        }
        $minIndex = 0;
        $count = count($cycle);
        for ($i = 1; $i < $count; $i++) {
            if (strcmp($cycle[$i], $cycle[$minIndex]) < 0) {
                $minIndex = $i;
            }
        }
        return array_merge(array_slice($cycle, $minIndex), array_slice($cycle, 0, $minIndex));
    }
}
