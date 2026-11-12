<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalNote extends Model
{
    protected $connection = 'secondary';

    protected $guarded = [];
}
