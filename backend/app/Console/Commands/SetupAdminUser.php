<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SetupAdminUser extends Command
{
    protected $signature = 'user:setup-admin {email} {--no-mfa : Skip MFA activation}';
    protected $description = 'Activate KYC, set admin role, and enable MFA for a user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = \App\Models\User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return 1;
        }

        $this->info("User found: {$user->name} ({$user->email}) — ID: {$user->id}");

        // Activate KYC
        $user->forceFill([
            'kyc_status' => 'approved',
            'kyc_approved_at' => now(),
            'kyc_rejection_reason' => null,
            'status' => 'active',
        ])->save();

        $this->info('KYC activated (status: approved)');

        // Enable MFA automatically (so admin panel doesn't block)
        if (!$this->option('no-mfa') && !$user->mfa_enabled) {
            $google2fa = app(\PragmaRX\Google2FA\Google2FA::class);
            $secret = $google2fa->generateSecretKey();

            $user->forceFill([
                'mfa_enabled' => true,
                'mfa_secret' => $secret,
            ])->save();

            $this->info('MFA enabled automatically');
            $this->warn("MFA Secret: {$secret}");
            $this->warn('Add this secret to Google Authenticator or similar app.');

            // Generate QR code URL for convenience
            $issuer = trim((string) \App\Models\SystemSetting::get('platform_name', ''));
            $qrUrl = $google2fa->getQRCodeUrl(
                $issuer !== '' ? $issuer : (string) config('app.name', 'Vector Pagamentos'),
                $user->email,
                $secret
            );
            $this->line("QR Code URL (open in browser): {$qrUrl}");
        } elseif ($user->mfa_enabled) {
            $this->info('MFA already enabled');
        }

        // Resolve admin role
        if (Schema::hasTable('roles')) {
            $adminRole = DB::table('roles')
                ->where('slug', 'admin')
                ->orWhere('name', 'admin')
                ->first();

            if ($adminRole) {
                $user->forceFill(['role_id' => $adminRole->id])->save();
                $this->info("Admin role assigned (role_id: {$adminRole->id})");
            } else {
                $roleId = Str::uuid()->toString();
                DB::table('roles')->insert([
                    'id' => $roleId,
                    'name' => 'Admin',
                    'slug' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $user->forceFill(['role_id' => $roleId])->save();
                $this->info("Admin role created and assigned (role_id: {$roleId})");
            }
        } else {
            $this->warn("Table 'roles' does not exist. role_id not changed.");
        }

        // Verify
        $user->refresh();
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $user->email],
                ['KYC Status', $user->kyc_status],
                ['KYC Approved At', $user->kyc_approved_at],
                ['Role ID', $user->role_id],
                ['Status', $user->status],
                ['MFA Enabled', $user->mfa_enabled ? 'YES' : 'NO'],
                ['isAdmin()', $user->isAdmin() ? 'YES' : 'NO'],
            ]
        );

        return 0;
    }
}
