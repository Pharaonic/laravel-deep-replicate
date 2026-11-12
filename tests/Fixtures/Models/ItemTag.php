<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ItemTag extends Pivot
{
    public $incrementing = true;

    protected $table = 'order_item_tag';

    protected $casts = ['primary' => 'boolean', 'options' => 'array'];

    /** @var list<string> */
    public static $events = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (ItemTag $pivot) {
            static::$events[] = 'creating';
        });

        static::created(function (ItemTag $pivot) {
            static::$events[] = 'created';
        });
    }
}
