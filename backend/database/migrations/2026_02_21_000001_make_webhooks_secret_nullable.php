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
            return;
        }

        // PostgreSQL syntax
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE webhooks ALTER COLUMN secret DROP NOT NULL');
            return;
        }

        // MySQL syntax
        DB::statement('ALTER TABLE webhooks MODIFY COLUMN secret VARCHAR(64) NULL');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        // PostgreSQL syntax
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE webhooks ALTER COLUMN secret SET NOT NULL');
            return;
        }

        // MySQL syntax
        DB::statement('ALTER TABLE webhooks MODIFY COLUMN secret VARCHAR(64) NOT NULL');
    }
};
