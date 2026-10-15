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


it('suppresses withdrawal failure notifications and hides existing ones from the inbox', function () {
    $withdrawal = new \App\Models\Withdrawal([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 4000,
    ]);
    app(\App\Services\AccountNotificationService::class)->withdrawalFailed($withdrawal, 'Gateway unavailable');
    expect(Notification::count())->toBe(0);

    foreach ([$this->user->id, null] as $userId) {
        Notification::create([
            'account_id' => $this->account->id,
            'user_id' => $userId,
            'type' => 'warning',
            'title' => 'Withdrawal failed',
            'message' => 'Gateway unavailable',
            'data' => ['event' => 'withdrawal.failed'],
            'read' => false,
        ]);
    }
    Notification::create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'Other notification',
        'message' => 'Still visible',
        'read' => false,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.unread_count', 1);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);
});


it('clears all personal and account notifications only for the requesting user', function () {
    foreach (range(1, 25) as $index) {
        Notification::create([
            'account_id' => $this->account->id,
            'user_id' => $index % 2 ? $this->user->id : null,
            'type' => 'info',
            'title' => 'Notification',
            'message' => 'Test',
            'read' => false,
        ]);
    }

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson('/api/v1/notifications/clear-all')->assertOk();
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
    expect(Notification::count())->toBe(25);
    expect(\App\Models\NotificationRead::where('user_id', $this->user->id)->whereNotNull('deleted_at')->count())->toBe(25);

    $other = User::create([
        'account_id' => $this->account->id,
        'name' => 'Other user',
        'email' => 'other-notifications@test.com',
        'password' => bcrypt('password'),
        'status' => 'active',
    ]);
    $otherToken = auth()->login($other);
    $this->withHeader('Authorization', "Bearer {$otherToken}")->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.unread_count', 12);
});
