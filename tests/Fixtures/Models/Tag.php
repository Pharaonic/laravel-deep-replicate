<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $guarded = [];

    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable')->withPivot('context');
    }

    public function videos()
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }
}
