<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class StrictUser extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(StrictTeam::class, 'strict_team_id');
    }
}
