<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $guarded = [];

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
