<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Mimics the native unique id API of newer Laravel versions (uniqueIds()
 * and newUniqueId()), which Laravel 6 does not ship.
 */
class Ticket extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static int $generated = 0;

    public function uniqueIds()
    {
        return ['id', 'reference'];
    }

    public function newUniqueId()
    {
        static::$generated++;

        return (string) Str::uuid();
    }
}
