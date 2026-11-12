<?php

use Pharaonic\Laravel\DeepReplicate\DeepReplicateServiceProvider;
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\ReplicationBuilder;

test('it registers the service provider', function () {
    expect($this->app->getProviders(DeepReplicateServiceProvider::class))->not->toBeEmpty();
});

test('it binds one stateless replicator', function () {
    expect($this->app->make(DeepReplicator::class))->toBeInstanceOf(DeepReplicator::class);
    expect($this->app->make(DeepReplicator::class))->toBe($this->app->make(DeepReplicator::class));
});

test('the facade resolves the replicator and returns new builders', function () {
    $order = $this->seedOrder();

    expect(DeepReplicate::getFacadeRoot())->toBeInstanceOf(DeepReplicator::class);
    expect(DeepReplicate::for($order))->toBeInstanceOf(ReplicationBuilder::class);
    expect(DeepReplicate::for($order))->not->toBe(DeepReplicate::for($order));
    expect(\DeepReplicate::for($order))->toBeInstanceOf(ReplicationBuilder::class);
});

test('composer declares auto discovery', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe([DeepReplicateServiceProvider::class]);
    expect($composer['extra']['laravel']['aliases']['DeepReplicate'])->toBe(DeepReplicate::class);
});
