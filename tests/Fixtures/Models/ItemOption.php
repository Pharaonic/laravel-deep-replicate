<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class ItemOption extends Model
{
    protected $guarded = [];

    public function item()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
