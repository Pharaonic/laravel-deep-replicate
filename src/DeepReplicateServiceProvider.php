<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Support\ServiceProvider;

final class DeepReplicateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeepReplicator::class);
    }
}
