<?php

namespace Database\Seeders;

use App\Models\KycSubmission;
use App\Models\KycDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class KycTestSeeder extends Seeder
{
    /**
     * Seed test KYC submissions for development/testing
     */
    public function run(): void
    {
        $now = now();
        
        // Get customer role
        $customerRole = DB::table('roles')->where('slug', 'customer')->first();
        
        // Create test users with pending KYC
        $testUsers = [
            [
                'name' => 'João Silva',
                'email' => 'joao.silva@teste.com',
                'document' => '12345678900',
                'phone' => '(11) 99999-1111',
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@teste.com',
                'document' => '98765432100',
                'phone' => '(21) 98888-2222',
            ],
            [
                'name' => 'Pedro Oliveira',
                'email' => 'pedro.oliveira@teste.com',
                'document' => '45678912300',
                'phone' => '(31) 97777-3333',
            ],
        ];

        $this->command->info('🔄 Creating test KYC submissions...');

        foreach ($testUsers as $userData) {
            // Check if user already exists
            $existingUser = DB::table('users')->where('email', $userData['email'])->first();
            
            if ($existingUser) {
                // Check if submission already exists
                $existingSubmission = KycSubmission::where('user_id', $existingUser->id)->first();
                if ($existingSubmission) {
                    $this->command->info("⏭️ KYC submission already exists for {$userData['name']}");
                    continue;
                }
                
                // Update user's kyc_status
                DB::table('users')
                    ->where('id', $existingUser->id)
                    ->update([
                        'kyc_status' => 'submitted',
                        'kyc_submitted_at' => $now->subDays(rand(1, 7)),
                    ]);
                
                $userId = $existingUser->id;
            } else {
                // Create account first
                $accountId = Str::uuid()->toString();
                $userId = Str::uuid()->toString();
                
                DB::table('accounts')->insert([
                    'id' => $accountId,
                    'name' => $userData['name'],
                    'document' => $userData['document'],
                    'document_type' => 'cpf',
                    'email' => $userData['email'],
                    'phone' => $userData['phone'],
                    'status' => 'active',
                    'settings' => '{}',
                    'metadata' => '{}',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                
                // Create user
                DB::table('users')->insert([
                    'id' => $userId,
                    'account_id' => $accountId,
                    'role_id' => $customerRole?->id,
                    'email' => $userData['email'],
                    'password' => Hash::make('password123'),
                    'name' => $userData['name'],
                    'phone' => $userData['phone'],
                    'email_verified_at' => $now,
                    'mfa_enabled' => false,
                    'kyc_status' => 'submitted',
                    'kyc_submitted_at' => $now->subDays(rand(1, 7)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Create KYC submission
            $submissionId = Str::uuid()->toString();
            KycSubmission::create([
                'id' => $submissionId,
                'user_id' => $userId,
                'status' => 'pending',
                'full_name' => $userData['name'],
                'birth_date' => now()->subYears(rand(25, 45))->format('Y-m-d'),
                'document_number' => $userData['document'],
                'phone' => $userData['phone'],
                'address_street' => 'Rua Exemplo',
                'address_number' => (string) rand(100, 999),
                'address_complement' => rand(0, 1) ? 'Apto ' . rand(1, 100) : null,
                'address_neighborhood' => 'Centro',
                'address_city' => 'São Paulo',
                'address_state' => 'SP',
                'address_zip_code' => '01000-000',
                'account_type' => 'individual',
                'created_at' => $now->subDays(rand(1, 7)),
            ]);

            // Create sample documents
            $documentTypes = ['identity_front', 'identity_back', 'selfie'];
            foreach ($documentTypes as $docType) {
                KycDocument::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $userId,
                    'document_type' => $docType,
                    'file_path' => "kyc/{$userId}/" . Str::uuid() . '.jpg',
                    'file_name' => $docType . '_' . date('Y-m-d') . '.jpg',
                    'mime_type' => 'image/jpeg',
                    'file_size' => rand(50000, 500000),
                    'status' => 'pending',
                ]);
            }

            $this->command->info("✅ Created KYC submission for {$userData['name']}");
        }

        $this->command->info('');
        $this->command->info('✅ KYC test data created successfully!');
        $this->command->info('📝 Total pending submissions: ' . KycSubmission::where('status', 'pending')->count());
    }
}
