<?php

it('boots the application with the api routes file loaded', function () {
    $response = $this->getJson('/api/this-route-does-not-exist');

    $response->assertStatus(404);
});
