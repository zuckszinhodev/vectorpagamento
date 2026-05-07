<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acquirer_configs')) {
            return;
        }

        $row = DB::table('acquirer_configs')
            ->select('id', 'supported_methods')
            ->where('acquirer_key', 'enki')
            ->first();

        if (!$row) {
            return;
        }

        $methods = json_decode((string) ($row->supported_methods ?? '[]'), true);
        if (!is_array($methods)) {
            $methods = [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            $value = strtolower(trim((string) $method));
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        if (!in_array('pix', $normalized, true)) {
            $normalized[] = 'pix';
        }

        if (!in_array('pix_out', $normalized, true)) {
            $normalized[] = 'pix_out';
        }

        DB::table('acquirer_configs')
            ->where('id', $row->id)
            ->update([
                'supported_methods' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('acquirer_configs')) {
            return;
        }

        $row = DB::table('acquirer_configs')
            ->select('id', 'supported_methods')
            ->where('acquirer_key', 'enki')
            ->first();

        if (!$row) {
            return;
        }

        $methods = json_decode((string) ($row->supported_methods ?? '[]'), true);
        if (!is_array($methods)) {
            $methods = [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            $value = strtolower(trim((string) $method));
            if ($value === '' || $value === 'pix_out' || in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        DB::table('acquirer_configs')
            ->where('id', $row->id)
            ->update([
                'supported_methods' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }
};
