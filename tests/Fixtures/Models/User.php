<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class User extends Model
{
    use DeepReplicates;

    protected $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function currentTeam()
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
