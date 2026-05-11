<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->string('group')->default('general'); // general, appearance, api, webhooks, acquirers
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('group');
        });

        // Insert default settings
        if (DB::table('system_settings')->count() === 0) {
            DB::table('system_settings')->insert([
                // Appearance settings
                ['key' => 'platform_name', 'value' => 'Vector Pagamentos', 'type' => 'string', 'group' => 'appearance', 'description' => 'Nome da plataforma', 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'primary_color', 'value' => '#3B82F6', 'type' => 'string', 'group' => 'appearance', 'description' => 'Cor principal da plataforma', 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'logo_url', 'value' => null, 'type' => 'string', 'group' => 'appearance', 'description' => 'URL do logo da plataforma', 'created_at' => now(), 'updated_at' => now()],
                
                // API settings
                ['key' => 'rate_limit_per_minute', 'value' => '1000', 'type' => 'integer', 'group' => 'api', 'description' => 'Limite de requisiÃ§Ãµes por minuto', 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'webhook_timeout', 'value' => '30', 'type' => 'integer', 'group' => 'api', 'description' => 'Timeout de webhooks em segundos', 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'cors_enabled', 'value' => 'true', 'type' => 'boolean', 'group' => 'api', 'description' => 'Permitir requisiÃ§Ãµes cross-origin', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
