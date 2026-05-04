<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Notification;
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

it('lists notifications for authenticated user', function () {
    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'sale',
        'title'      => 'Payment Received',
        'message'    => 'You received a payment',
        'read_at'    => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/notifications');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('marks notification as read', function () {
    $notification = Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'info',
        'title'      => 'Test',
        'message'    => 'Test message',
        'read_at'    => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/notifications/{$notification->id}/read");

    $response->assertStatus(200);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
    ]);

    $notification->refresh();
    expect($notification->read_at)->not->toBeNull();
});

it('marks all notifications as read', function () {
    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'info',
        'title'      => 'Test 1',
        'message'    => 'Message 1',
        'read_at'    => null,
    ]);

    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'info',
        'title'      => 'Test 2',
        'message'    => 'Message 2',
        'read_at'    => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/notifications/read-all');

    $response->assertStatus(200);

    $unread = Notification::where('user_id', $this->user->id)
        ->whereNull('read_at')
        ->count();

    expect($unread)->toBe(0);
});

it('deletes a notification', function () {
    $notification = Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'info',
        'title'      => 'Test',
        'message'    => 'Test message',
        'read_at'    => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/notifications/{$notification->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
});

it('counts unread notifications', function () {
    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'sale',
        'title'      => 'Unread 1',
        'message'    => 'Message',
        'read_at'    => null,
    ]);

    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'sale',
        'title'      => 'Unread 2',
        'message'    => 'Message',
        'read_at'    => null,
    ]);

    Notification::create([
        'id'         => Str::uuid()->toString(),
        'user_id'    => $this->user->id,
        'account_id' => $this->account->id,
        'type'       => 'info',
        'title'      => 'Read',
        'message'    => 'Message',
        'read'       => true,
        'read_at'    => now(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/notifications/unread-count');

    $response->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['unread_count' => 2]]);
});
