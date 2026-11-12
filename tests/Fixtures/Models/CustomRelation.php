<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class CustomRelation extends Relation
{
    public function addConstraints()
    {
    }

    public function addEagerConstraints(array $models)
    {
    }

    public function initRelation(array $models, $relation)
    {
        return $models;
    }

    public function match(array $models, Collection $results, $relation)
    {
        return $models;
    }

    public function getResults()
    {
        return $this->query->get();
    }
}
