<?php

declare(strict_types=1);

use App\Mail\AcquirerAnnouncementMail;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function announcementUser(string $email, array $attributes = []): User
{
    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Conta ' . $email,
        'document' => (string) random_int(10000000000, 99999999999),
        'document_type' => 'cpf',
        'email' => $email,
        'status' => 'active',
    ]);

    $user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email' => $email,
        'password' => bcrypt('password'),
        'name' => 'User ' . $email,
        'status' => 'active',
    ]);

    if ($attributes !== []) {
        $user->forceFill($attributes)->save();
    }

    return $user;
}

beforeEach(function () {
    announcementUser('ativo1@teste.com');
    announcementUser('ativo2@teste.com');
    announcementUser('bloqueado@teste.com', ['status' => 'blocked']);
    announcementUser('excluido@teste.com')->delete();
    Mail::fake();
});

it('only lists recipients without options', function () {
    $this->artisan('mail:announce-acquirer')
        ->expectsOutputToContain('Destinatários: 2')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

it('sends a single test email with --test', function () {
    $this->artisan('mail:announce-acquirer', ['--test' => 'ativo1@teste.com'])->assertSuccessful();

    Mail::assertSent(AcquirerAnnouncementMail::class, 1);
    Mail::assertSent(AcquirerAnnouncementMail::class, fn ($mail) => $mail->hasTo('ativo1@teste.com'));
});

it('sends to active users only with --send', function () {
    $this->artisan('mail:announce-acquirer', ['--send' => true])->assertSuccessful();

    Mail::assertSent(AcquirerAnnouncementMail::class, 2);
    Mail::assertNotSent(AcquirerAnnouncementMail::class, fn ($mail) => $mail->hasTo('bloqueado@teste.com'));
    Mail::assertNotSent(AcquirerAnnouncementMail::class, fn ($mail) => $mail->hasTo('excluido@teste.com'));
});

it('renders the announcement with banner and support link', function () {
    $html = (new AcquirerAnnouncementMail(User::first()))->render();

    expect($html)
        ->toContain('/img/email/stone-pagarme.jpg')
        ->toContain('STONE | PAGAR.ME')
        ->toContain('0 MED')
        ->toContain('/support');
});
