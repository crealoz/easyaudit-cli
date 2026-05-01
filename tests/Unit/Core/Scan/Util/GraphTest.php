<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Graph;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    public function testSimpleTwoNodeCycle(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['B'],
            'B' => ['A'],
        ]);

        $this->assertCount(1, $cycles);
        $this->assertEquals(['A', 'B'], $cycles[0]);
    }

    public function testThreeNodeCycle(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ]);

        $this->assertCount(1, $cycles);
        $this->assertEquals(['A', 'B', 'C'], $cycles[0]);
    }

    public function testSelfLoopIsCycle(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['A'],
        ]);

        $this->assertCount(1, $cycles);
        $this->assertEquals(['A'], $cycles[0]);
    }

    public function testDagHasNoCycles(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['B', 'C'],
            'B' => ['D'],
            'C' => ['D'],
            'D' => [],
        ]);

        $this->assertEmpty($cycles);
    }

    public function testCanonicalizationStartsAtSmallestNode(): void
    {
        $cycles = Graph::detectCycles([
            'zebra' => ['apple'],
            'apple' => ['banana'],
            'banana' => ['zebra'],
        ]);

        $this->assertCount(1, $cycles);
        $this->assertEquals('apple', $cycles[0][0]);
    }

    public function testDuplicateCyclesCollapsedOnce(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['B'],
            'B' => ['A'],
            'C' => ['A'],
        ]);

        $this->assertCount(1, $cycles);
    }

    public function testNodesInNeighborsButNotKeysAreHandled(): void
    {
        $cycles = Graph::detectCycles([
            'A' => ['B'],
            'B' => ['C'],
            // C is a neighbor but not a key, so it has no outgoing edges.
        ]);

        $this->assertEmpty($cycles);
    }
}
