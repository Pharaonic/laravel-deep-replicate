<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Unit;

use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\ReplicationPlan;
use Pharaonic\Laravel\DeepReplicate\ReplicationStats;
use PHPUnit\Framework\TestCase;

class ValueObjectsTest extends TestCase
{
    public function test_stats_expose_every_counter(): void
    {
        $stats = new ReplicationStats(5, 2, 1, 7, 3, 12.5);

        $this->assertSame(
            ['copiedModels' => 5, 'reusedModels' => 2, 'skippedRelationships' => 1, 'pivotRowsCreated' => 7, 'maxDepthReached' => 3, 'durationMs' => 12.5],
            $stats->toArray()
        );
    }

    public function test_a_plan_sums_its_estimates(): void
    {
        $warning = new PlanningWarning(PlanningWarning::MISSING_TARGET, 'customer', 'kept');
        $plan = new ReplicationPlan(['customer' => 'copy'], [$warning], new ReplicationStats(1, 4, 0, 9, 1, 0.1));

        $this->assertSame(5, $plan->estimatedModels());
        $this->assertSame(9, $plan->estimatedPivotRows());
        $this->assertSame(['customer' => 'copy'], $plan->relationships());
        $this->assertSame('kept', (string) $plan->warnings()[0]);
        $this->assertSame('customer', $warning->path());
    }
}
