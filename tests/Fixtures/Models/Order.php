<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class Order extends Model
{
    use DeepReplicates;
    use SoftDeletes;

    protected $fillable = ['number'];

    protected $hidden = ['secret'];

    protected $casts = ['meta' => 'array', 'total' => 'decimal:2'];

    /** @var array<string, mixed>|null */
    public static $defaults = null;

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedCustomer()
    {
        return $this->belongsTo(Customer::class, 'assigned_customer_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function activeItems()
    {
        return $this->hasMany(OrderItem::class)->where('active', true);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function uniqueTags()
    {
        return $this->belongsToMany(Tag::class, 'order_tag_unique');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function notes()
    {
        return $this->hasMany(ExternalNote::class);
    }

    public function localNotes()
    {
        return $this->hasMany(DefaultConnectionNote::class, 'order_id');
    }

    public function itemProducts()
    {
        return $this->hasManyThrough(Product::class, OrderItem::class, 'order_id', 'id', 'id', 'product_id');
    }

    public function firstItemProduct()
    {
        return $this->hasOneThrough(Product::class, OrderItem::class, 'order_id', 'id', 'id', 'product_id');
    }

    public function custom()
    {
        return new CustomRelation($this->newQuery(), $this);
    }

    public function label(): string
    {
        return 'order';
    }

    public function computed()
    {
        return 42;
    }

    private function hidden()
    {
        return $this->hasMany(Payment::class);
    }

    public static function staticRelation()
    {
        return null;
    }

    public function withArgument($value)
    {
        return $this->hasMany(Payment::class);
    }

    public function getSecretAttribute($value)
    {
        return $value === null ? null : decrypt($value);
    }

    public function setSecretAttribute($value): void
    {
        $this->attributes['secret'] = $value === null ? null : encrypt($value);
    }

    public function setNumberAttribute($value): void
    {
        $this->attributes['number'] = strtoupper((string) $value);
    }

    protected function deepReplicationDefaults(): array
    {
        return static::$defaults ?? [];
    }
}
