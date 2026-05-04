<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add KYC status to users table
        Schema::table('users', function (Blueprint $table) {
            $table->enum('kyc_status', ['pending', 'submitted', 'under_review', 'approved', 'rejected'])
                ->default('pending')
                ->after('mfa_secret');
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_status');
            $table->timestamp('kyc_approved_at')->nullable()->after('kyc_submitted_at');
            $table->text('kyc_rejection_reason')->nullable()->after('kyc_approved_at');
        });

        // Create KYC documents table
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('document_type', [
                'identity_front',      // Frente do documento (RG/CNH)
                'identity_back',       // Verso do documento
                'selfie',              // Selfie com documento
                'proof_of_address',    // Comprovante de endereço
                'company_document',    // Contrato social / CNPJ
            ]);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->index('user_id');
            $table->index(['user_id', 'document_type']);
            $table->index('status');
        });

        // Create KYC submissions table (tracks each submission attempt)
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending');
            
            // Personal data
            $table->string('full_name');
            $table->date('birth_date');
            $table->string('document_number', 20); // CPF
            $table->string('phone', 20)->nullable();
            
            // Address
            $table->string('address_street')->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('address_neighborhood')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_zip_code', 10)->nullable();
            
            // Company data (if applicable)
            $table->string('company_name')->nullable();
            $table->string('company_document', 20)->nullable(); // CNPJ
            $table->enum('account_type', ['individual', 'company'])->default('individual');
            
            // Review info
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->index('user_id');
            $table->index('status');
            $table->index('document_number');
        });

        // Create KYC activity log
        Schema::create('kyc_activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('submission_id')->nullable();
            $table->string('action'); // submitted, approved, rejected, document_uploaded, etc
            $table->json('metadata')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('submission_id')->references('id')->on('kyc_submissions')->onDelete('set null');
            $table->foreign('performed_by')->references('id')->on('users')->onDelete('set null');

            $table->index('user_id');
            $table->index('submission_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_activity_logs');
        Schema::dropIfExists('kyc_submissions');
        Schema::dropIfExists('kyc_documents');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'kyc_status',
                'kyc_submitted_at',
                'kyc_approved_at',
                'kyc_rejection_reason',
            ]);
        });
    }
};
