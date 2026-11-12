<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'uuid';

    protected $guarded = [];

    public function pages()
    {
        return $this->hasMany(Page::class, 'document_uuid', 'uuid');
    }
}
