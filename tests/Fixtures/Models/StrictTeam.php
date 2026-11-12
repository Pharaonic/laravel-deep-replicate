<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class StrictTeam extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function owner()
    {
        return $this->belongsTo(StrictUser::class, 'owner_id');
    }
}
