<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable()->index();
            $table->uuid('requester_user_id')->index();
            $table->uuid('reviewer_user_id')->nullable()->index();
            $table->string('action_key', 120)->index();
            $table->string('resource_type', 80)->index();
            $table->string('resource_id', 120)->nullable()->index();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'consumed'])->default('pending')->index();
            $table->jsonb('payload')->nullable();
            $table->text('request_reason')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('requested_at')->useCurrent()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['action_key', 'resource_type', 'resource_id'], 'admin_approvals_action_resource_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX admin_action_approvals_pending_unique ON admin_action_approvals (action_key, resource_type, COALESCE(resource_id, ''), requester_user_id) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_action_approvals') && Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS admin_action_approvals_pending_unique');
        }

        Schema::dropIfExists('admin_action_approvals');
    }
};
