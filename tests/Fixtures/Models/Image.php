<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $guarded = [];

    protected $casts = ['featured' => 'boolean'];

    public function imageable()
    {
        return $this->morphTo();
    }
}
