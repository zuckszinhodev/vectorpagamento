<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite treats all text types the same (TEXT affinity), no migration needed
            return;
        }

        // Change credentials from json to text since it stores encrypted (base64) strings, not JSON
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE acquirer_configs ALTER COLUMN credentials TYPE text USING credentials::text');
        } else {
            // MySQL / MariaDB
            DB::statement('ALTER TABLE acquirer_configs MODIFY COLUMN credentials TEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE acquirer_configs ALTER COLUMN credentials TYPE json USING credentials::json');
        } else {
            DB::statement('ALTER TABLE acquirer_configs MODIFY COLUMN credentials JSON NULL');
        }
    }
};
