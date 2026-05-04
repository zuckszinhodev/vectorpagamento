<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('document', 20)->unique();
            $table->enum('document_type', ['cpf', 'cnpj']);
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->enum('status', ['pending', 'active', 'suspended', 'blocked'])->default('pending');
            $table->jsonb('settings')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
