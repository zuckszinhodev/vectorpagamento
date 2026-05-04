<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('account_custom_fees')) {
            return;
        }

        // Rename _cents columns to plain names and convert integer cents → decimal reais
        $renames = [
            'pix_fee_fixed_cents' => 'pix_fee_fixed',
            'credit_card_fee_fixed_cents' => 'credit_card_fee_fixed',
            'boleto_fee_fixed_cents' => 'boleto_fee_fixed',
            'withdrawal_pix_fee_cents' => 'withdrawal_pix_fee',
            'withdrawal_ted_fee_cents' => 'withdrawal_ted_fee',
            'antifraude_fee_fixed_cents' => 'antifraude_fee_fixed',
            'min_withdrawal_cents' => 'min_withdrawal',
        ];

        // Step 1: Rename columns
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ($renames as $old => $new) {
                if (Schema::hasColumn('account_custom_fees', $old)) {
                    Schema::table('account_custom_fees', function (Blueprint $table) use ($old, $new) {
                        $table->renameColumn($old, $new);
                    });
                }
            }
        } else {
            Schema::table('account_custom_fees', function (Blueprint $table) use ($renames) {
                foreach ($renames as $old => $new) {
                    if (Schema::hasColumn('account_custom_fees', $old)) {
                        $table->renameColumn($old, $new);
                    }
                }
            });
        }

        // Step 2: Change column types from integer to decimal
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ($renames as $old => $new) {
                if (Schema::hasColumn('account_custom_fees', $new)) {
                    Schema::table('account_custom_fees', function (Blueprint $table) use ($new) {
                        $table->decimal($new, 10, 2)->nullable()->change();
                    });
                }
            }
        } else {
            Schema::table('account_custom_fees', function (Blueprint $table) use ($renames) {
                foreach ($renames as $old => $new) {
                    $table->decimal($new, 10, 2)->nullable()->change();
                }
            });
        }

        // Step 3: Convert existing centavo values to reais (divide by 100)
        foreach ($renames as $old => $new) {
            DB::table('account_custom_fees')
                ->whereNotNull($new)
                ->update([$new => DB::raw("{$new} / 100.0")]);
        }
    }

    public function down(): void
    {
        $renames = [
            'pix_fee_fixed' => 'pix_fee_fixed_cents',
            'credit_card_fee_fixed' => 'credit_card_fee_fixed_cents',
            'boleto_fee_fixed' => 'boleto_fee_fixed_cents',
            'withdrawal_pix_fee' => 'withdrawal_pix_fee_cents',
            'withdrawal_ted_fee' => 'withdrawal_ted_fee_cents',
            'antifraude_fee_fixed' => 'antifraude_fee_fixed_cents',
            'min_withdrawal' => 'min_withdrawal_cents',
        ];

        // Convert back to centavos
        foreach ($renames as $old => $new) {
            if (Schema::hasColumn('account_custom_fees', $old)) {
                DB::table('account_custom_fees')
                    ->whereNotNull($old)
                    ->update([$old => DB::raw("{$old} * 100")]);
            }
        }

        // Change type back to integer
        Schema::table('account_custom_fees', function (Blueprint $table) use ($renames) {
            foreach ($renames as $old => $new) {
                if (Schema::hasColumn('account_custom_fees', $old)) {
                    $table->integer($old)->nullable()->change();
                }
            }
        });

        // Rename back
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ($renames as $old => $new) {
                if (Schema::hasColumn('account_custom_fees', $old)) {
                    Schema::table('account_custom_fees', function (Blueprint $table) use ($old, $new) {
                        $table->renameColumn($old, $new);
                    });
                }
            }
        } else {
            Schema::table('account_custom_fees', function (Blueprint $table) use ($renames) {
                foreach ($renames as $old => $new) {
                    if (Schema::hasColumn('account_custom_fees', $old)) {
                        $table->renameColumn($old, $new);
                    }
                }
            });
        }
    }
};
