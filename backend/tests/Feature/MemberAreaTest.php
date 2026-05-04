<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
    ]);

    $this->user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email'      => 'user@test.com',
        'password'   => bcrypt('password'),
        'name'       => 'Test User',
        'status'     => 'active',
        'role'       => 'admin',
    ]);

    $this->token = auth()->login($this->user);
});

it('auto-creates member area on show', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/member-area');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['id', 'name']]);

    $this->assertDatabaseHas('member_areas', [
        'account_id' => $this->account->id,
    ]);
});

it('updates member area settings', function () {
    // First show to auto-create
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/member-area');

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/v1/member-area', [
            'name'          => 'My Course Platform',
            'description'   => 'Platform description',
            'primary_color' => '#FF0000',
            'theme_mode'    => 'dark',
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('member_areas', [
        'account_id'    => $this->account->id,
        'name'          => 'My Course Platform',
        'primary_color' => '#FF0000',
    ]);
});

it('creates a course in member area', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/member-area/courses', [
            'name'        => 'Test Course',
            'description' => 'Course description',
            'level'       => 'beginner',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name']]);

    $this->assertDatabaseHas('courses', [
        'name'       => 'Test Course',
        'account_id' => $this->account->id,
    ]);
});

it('lists courses for member area', function () {
    // Create course via API
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/member-area/courses', [
            'name' => 'Test Course',
        ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/member-area/courses');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('gets member area dashboard', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/member-area/dashboard');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});
