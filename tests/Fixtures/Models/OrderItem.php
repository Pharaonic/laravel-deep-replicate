<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function options()
    {
        return $this->hasMany(ItemOption::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class)->using(ItemTag::class)->withPivot('primary', 'weight', 'options')->withTimestamps();
    }

    public function plainTags()
    {
        return $this->belongsToMany(Tag::class, 'order_item_tag')->withTimestamps();
    }

    public function primaryTags()
    {
        return $this->belongsToMany(Tag::class, 'order_item_tag')->wherePivot('primary', true);
    }
}
