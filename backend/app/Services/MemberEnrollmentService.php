<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
        try {
            // Get product from charge
            $product = $charge->product;
            if (!$product) {
                Log::debug('No product associated with charge for auto-enrollment', ['charge_id' => $charge->id]);
                return null;
            }

            // Check if product has an associated course
            $course = Course::where('product_id', $product->id)->first();
            if (!$course) {
                Log::debug('Product has no associated course', ['product_id' => $product->id]);
                return null;
            }

            $customerEmail = $charge->customer_email;
            $customerName = $charge->customer_name ?? explode('@', $customerEmail)[0];
            $accountId = $charge->account_id;

            if (!$customerEmail) {
                Log::warning('Cannot create enrollment: no customer email', ['charge_id' => $charge->id]);
                return null;
            }

            // Find or create user
            $user = User::where('email', $customerEmail)->first();
            $isNewUser = false;
            
            if (!$user) {
                $user = User::create([
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'password' => Hash::make(Str::random(32)),
                    'account_id' => $accountId,
                ]);
                $isNewUser = true;

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
                return $existingEnrollment;
            }

            // Create enrollment
            $enrollment = Enrollment::create([
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

            // Send credentials email if new user
            if ($tempPassword) {
                $this->sendCredentialsEmail($customerEmail, $customerName, $tempPassword, $course->name ?? 'Curso');
            }

            return $enrollment;
        } catch (\Exception $e) {
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
    private function sendCredentialsEmail(string $email, string $name, string $password, string $courseName): void
    {
        try {
            $memberAreaUrl = config('app.frontend_url', 'https://app.wazerpay.com.br') . '/members/login';
            
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
