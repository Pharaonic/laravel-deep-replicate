<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Names the default connection explicitly; it must not count as another connection.
 */
class DefaultConnectionNote extends Model
{
    protected $connection = 'testing';

    protected $table = 'payments';

    protected $guarded = [];
}
