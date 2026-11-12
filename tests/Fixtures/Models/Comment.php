<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $guarded = [];

    protected $casts = ['approved' => 'boolean'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
