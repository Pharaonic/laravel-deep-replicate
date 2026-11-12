<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class Post extends Model
{
    use DeepReplicates;

    protected $guarded = [];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function approvedComments()
    {
        return $this->hasMany(Comment::class)->where('approved', true);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function featuredImage()
    {
        return $this->morphOne(Image::class, 'imageable')->where('featured', true);
    }
}
