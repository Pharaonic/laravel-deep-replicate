<?php

use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\ReplicationPlan;
use Pharaonic\Laravel\DeepReplicate\ReplicationStats;

test('stats expose every counter', function () {
    $stats = new ReplicationStats(5, 2, 1, 7, 3, 12.5);

    expect($stats->toArray())->toBe(['copiedModels' => 5, 'reusedModels' => 2, 'skippedRelationships' => 1, 'pivotRowsCreated' => 7, 'maxDepthReached' => 3, 'durationMs' => 12.5]);
});

test('a plan sums its estimates', function () {
    $warning = new PlanningWarning(PlanningWarning::MISSING_TARGET, 'customer', 'kept');
    $plan = new ReplicationPlan(['customer' => 'copy'], [$warning], new ReplicationStats(1, 4, 0, 9, 1, 0.1));

    expect($plan->estimatedModels())->toBe(5);
    expect($plan->estimatedPivotRows())->toBe(9);
    expect($plan->relationships())->toBe(['customer' => 'copy']);
    expect((string) $plan->warnings()[0])->toBe('kept');
    expect($warning->path())->toBe('customer');
});
