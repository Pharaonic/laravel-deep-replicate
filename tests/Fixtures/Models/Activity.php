<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class Activity extends Model
{
    use DeepReplicates;

    protected $guarded = [];

    public function subject()
    {
        return $this->morphTo();
    }
}
