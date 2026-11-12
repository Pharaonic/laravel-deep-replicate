<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    public $timestamps = false;

    protected $table = 'legacy_authors';

    protected $primaryKey = 'author_no';

    protected $guarded = [];
}
