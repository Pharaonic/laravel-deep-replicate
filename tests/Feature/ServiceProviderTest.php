<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Pharaonic\Laravel\DeepReplicate\DeepReplicateServiceProvider;
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\ReplicationBuilder;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_it_registers_the_service_provider(): void
    {
        $this->assertNotEmpty($this->app->getProviders(DeepReplicateServiceProvider::class));
    }

    public function test_it_binds_one_stateless_replicator(): void
    {
        $this->assertInstanceOf(DeepReplicator::class, $this->app->make(DeepReplicator::class));
        $this->assertSame($this->app->make(DeepReplicator::class), $this->app->make(DeepReplicator::class));
    }

    public function test_the_facade_resolves_the_replicator_and_returns_new_builders(): void
    {
        $order = $this->seedOrder();

        $this->assertInstanceOf(DeepReplicator::class, DeepReplicate::getFacadeRoot());
        $this->assertInstanceOf(ReplicationBuilder::class, DeepReplicate::for($order));
        $this->assertNotSame(DeepReplicate::for($order), DeepReplicate::for($order));
        $this->assertInstanceOf(ReplicationBuilder::class, \DeepReplicate::for($order));
    }

    public function test_composer_declares_auto_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame([DeepReplicateServiceProvider::class], $composer['extra']['laravel']['providers']);
        $this->assertSame(DeepReplicate::class, $composer['extra']['laravel']['aliases']['DeepReplicate']);
    }
}
