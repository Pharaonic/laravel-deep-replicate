<?php

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Engine\DependencyResolver;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\LinkAssignment;
use Pharaonic\Laravel\DeepReplicate\Engine\Strategy;

test('targets are ordered before their dependents', function () {
    [$a, $b, $c] = dependencyNodes(3);
    $a->assign(new LinkAssignment('b_id', $b, 'id', null, 'b'));
    $b->assign(new LinkAssignment('c_id', $c, 'id', null, 'b.c'));

    expect(nodeKeys((new DependencyResolver)->order([$a, $b, $c])))->toBe(['n2', 'n1', 'n0']);
    expect($a->links['b_id']->deferred)->toBeFalse();
});

test('independent nodes keep discovery order', function () {
    expect(nodeKeys((new DependencyResolver)->order(dependencyNodes(4))))->toBe(['n0', 'n1', 'n2', 'n3']);
});

test('a cycle is broken at its earliest node', function () {
    [$a, $b] = dependencyNodes(2);
    $a->assign(new LinkAssignment('b_id', $b, 'id', null, 'b'));
    $b->assign(new LinkAssignment('a_id', $a, 'id', null, 'b.a'));

    expect(nodeKeys((new DependencyResolver)->order([$a, $b])))->toBe(['n0', 'n1']);
    expect($a->links['b_id']->deferred)->toBeTrue();
    expect($b->links['a_id']->deferred)->toBeFalse();
});

test('a self reference is deferred', function () {
    [$a] = dependencyNodes(1);
    $a->assign(new LinkAssignment('parent_id', $a, 'id', null, 'parent'));

    expect(nodeKeys((new DependencyResolver)->order([$a])))->toBe(['n0']);
    expect($a->links['parent_id']->deferred)->toBeTrue();
});

/**
 * @return list<GraphNode>
 */
/**
 * @return list<GraphNode>
 */
function dependencyNodes(int $count): array
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
function nodeKeys(array $nodes): array
{
    return array_map(function (GraphNode $node) {
        return $node->key;
    }, $nodes);
}
