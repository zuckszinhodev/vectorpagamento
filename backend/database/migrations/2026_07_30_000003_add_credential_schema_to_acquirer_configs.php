<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an acquirer declare its own credential fields from the admin panel.
 *
 * Field definitions used to live in a hard-coded `match` inside AcquirerConfig,
 * whose `default` arm returned an empty array. The consequence was that a
 * newly registered acquirer rendered no credential inputs at all — there was
 * literally nowhere to type the token — and could never pass validation.
 *
 * Shape:
 *   [{"key":"api_key","label":"Token de Acesso","type":"password","required":true}]
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acquirer_configs') && !Schema::hasColumn('acquirer_configs', 'credential_schema')) {
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->json('credential_schema')->nullable()->after('supported_methods');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('acquirer_configs') && Schema::hasColumn('acquirer_configs', 'credential_schema')) {
            Schema::table('acquirer_configs', function (Blueprint $table) {
                $table->dropColumn('credential_schema');
            });
        }
    }
};
