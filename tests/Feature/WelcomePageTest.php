<?php

use App\Models\User;

test('welcome page is accessible to guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Create account')
        ->assertDontSee('Get started')
        ->assertInertia(fn ($page) => $page
            ->component('Welcome')
            ->where('auth.user', null));
});

test('welcome page is accessible to authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Welcome')
            ->where('auth.user.id', $user->id));
});
