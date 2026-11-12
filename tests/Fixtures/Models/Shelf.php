<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Shelf extends Model
{
    public $timestamps = false;

    protected $table = 'legacy_shelves';

    protected $primaryKey = 'shelf_no';

    protected $guarded = [];

    public function books()
    {
        return $this->hasMany(Book::class, 'shelf_code', 'code');
    }
}
