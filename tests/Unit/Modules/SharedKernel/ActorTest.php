<?php

use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ActorType;

it('creates a system actor with no id', function () {
    $actor = Actor::system();

    expect($actor->type)->toBe(ActorType::System)
        ->and($actor->id)->toBeNull();
});

it('creates an api caller actor with an id', function () {
    $actor = Actor::apiCaller('caller-42');

    expect($actor->type)->toBe(ActorType::ApiCaller)
        ->and($actor->id)->toBe('caller-42');
});
