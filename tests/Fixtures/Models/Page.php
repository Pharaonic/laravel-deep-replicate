<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'uuid';

    protected $guarded = [];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_uuid', 'uuid');
    }
}
