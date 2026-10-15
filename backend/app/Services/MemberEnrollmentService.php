<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\MemberArea;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MemberEnrollmentService
{
    /**
     * Create automatic enrollment when a payment is approved for a product with a course
     */
    public function createAutomaticEnrollment(Charge $charge): ?Enrollment
    {
        if (!in_array($charge->status, ['approved', 'paid'], true)) {
            return null;
        }
        $transactionStarted = false;
        try {
            $product = $charge->product;
            if (!$product) {
                return null;
            }

            $course = Course::where('product_id', $product->id)
                ->where('account_id', $charge->account_id)->first();
            if (!$course) {
                return null;
            }

            $customerEmail = data_get($charge->customer, 'email');
            $accountId = $charge->account_id;

            if (!$customerEmail) {
                Log::warning('Cannot create enrollment: no customer email', ['charge_id' => $charge->id]);
                return null;
            }
            $customerName = data_get($charge->customer, 'name') ?: explode('@', $customerEmail)[0];
            $tempPassword = null;

            DB::beginTransaction();
            $transactionStarted = true;
            Charge::whereKey($charge->id)->lockForUpdate()->firstOrFail();

            // Find or create user
            $user = User::where('email', $customerEmail)->first();
            
            if (!$user) {
                $tempPassword = Str::random(24);
                $user = User::create([
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'password' => Hash::make($tempPassword),
                    'account_id' => $accountId,
                ]);

                Log::info('Created user for auto-enrollment', ['user_id' => $user->id, 'email' => $customerEmail]);
            }

            // Check for existing enrollment
            $existingEnrollment = Enrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($existingEnrollment) {
                // Reactivate if exists and not active
                if ($existingEnrollment->status !== 'active') {
                    $existingEnrollment->update([
                        'status' => 'active',
                        'enrolled_at' => now(),
                    ]);
                    Log::info('Reactivated existing enrollment', ['enrollment_id' => $existingEnrollment->id]);
                }
            }

            // Create enrollment
            $enrollment = $existingEnrollment ?? Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => 'active',
                'access_type' => 'lifetime',
                'enrolled_at' => now(),
            ]);

            // Create member record if not exists
            $member = Member::where('account_id', $accountId)
                ->where('email', $customerEmail)
                ->first();

            if (!$member) {
                // An existing dashboard user also needs credentials for their
                // separate member account. Do not change the dashboard password.
                $tempPassword ??= Str::random(24);
                $memberData = [
                    'account_id' => $accountId,
                    'user_id' => $user->id,
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'status' => 'active',
                ];
                
                // If new user, also save password hash in member for login
                if ($tempPassword) {
                    $memberData['password'] = Hash::make($tempPassword);
                }
                
                Member::create($memberData);
            }

            Log::info('Auto-enrollment created successfully', [
                'charge_id' => $charge->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment->id,
            ]);

            DB::commit();
            $transactionStarted = false;

            // Send credentials only for a newly created member.
            if ($tempPassword) {
                $this->sendCredentialsEmail($customerEmail, $customerName, $tempPassword, $course->name ?? 'Curso', $accountId);
            }

            return $enrollment;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            Log::error('Error creating auto-enrollment', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send credentials email to new member
     */
    private function sendCredentialsEmail(string $email, string $name, string $password, string $courseName, string $accountId): void
    {
        try {
            $area = MemberArea::where('account_id', $accountId)->first();
            $memberAreaUrl = rtrim($area?->full_domain ?: config('app.frontend_url', 'https://app.vectorpagamentos.com'), '/') . '/members/login';
            
            Mail::to($email)->queue(new \App\Mail\MemberCredentialsMail(
                $name,
                $email,
                $password,
                $courseName,
                $memberAreaUrl
            ));
            
            Log::info('Sent member credentials email', ['email' => $email]);
        } catch (\Exception $e) {
            Log::warning('Failed to send member credentials email', ['error' => $e->getMessage()]);
        }
    }
}
