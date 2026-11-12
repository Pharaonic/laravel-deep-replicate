<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Engine\DependencyResolver;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\LinkAssignment;
use Pharaonic\Laravel\DeepReplicate\Engine\Strategy;
use PHPUnit\Framework\TestCase;

class DependencyResolverTest extends TestCase
{
    public function test_targets_are_ordered_before_their_dependents(): void
    {
        [$a, $b, $c] = $this->nodes(3);
        $a->assign(new LinkAssignment('b_id', $b, 'id', null, 'b'));
        $b->assign(new LinkAssignment('c_id', $c, 'id', null, 'b.c'));

        $this->assertSame(['n2', 'n1', 'n0'], $this->keys((new DependencyResolver)->order([$a, $b, $c])));
        $this->assertFalse($a->links['b_id']->deferred);
    }

    public function test_independent_nodes_keep_discovery_order(): void
    {
        $this->assertSame(['n0', 'n1', 'n2', 'n3'], $this->keys((new DependencyResolver)->order($this->nodes(4))));
    }

    public function test_a_cycle_is_broken_at_its_earliest_node(): void
    {
        [$a, $b] = $this->nodes(2);
        $a->assign(new LinkAssignment('b_id', $b, 'id', null, 'b'));
        $b->assign(new LinkAssignment('a_id', $a, 'id', null, 'b.a'));

        $this->assertSame(['n0', 'n1'], $this->keys((new DependencyResolver)->order([$a, $b])));
        $this->assertTrue($a->links['b_id']->deferred);
        $this->assertFalse($b->links['a_id']->deferred);
    }

    public function test_a_self_reference_is_deferred(): void
    {
        [$a] = $this->nodes(1);
        $a->assign(new LinkAssignment('parent_id', $a, 'id', null, 'parent'));

        $this->assertSame(['n0'], $this->keys((new DependencyResolver)->order([$a])));
        $this->assertTrue($a->links['parent_id']->deferred);
    }

    /**
     * @return list<GraphNode>
     */
    private function nodes(int $count): array
    {
        $nodes = [];

        for ($i = 0; $i < $count; $i++) {
            $nodes[] = new GraphNode('n'.$i, new class extends Model
            {
            }, Strategy::COPY, $i, 0, '');
        }

        return $nodes;
    }

    /**
     * @param  list<GraphNode>  $nodes
     * @return list<string>
     */
    private function keys(array $nodes): array
    {
        return array_map(function (GraphNode $node) {
            return $node->key;
        }, $nodes);
    }
}
