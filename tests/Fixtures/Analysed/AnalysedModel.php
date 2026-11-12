<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Analysed;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

/**
 * Lets PHPStan analyse the trait, which it only analyses through a class
 * that uses it.
 */
final class AnalysedModel extends Model
{
    use DeepReplicates;
}
