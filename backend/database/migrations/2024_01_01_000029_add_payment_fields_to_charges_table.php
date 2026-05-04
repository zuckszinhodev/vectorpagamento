<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // PIX fields
            if (!Schema::hasColumn('charges', 'pix_qr_code')) {
                $table->text('pix_qr_code')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('charges', 'pix_copy_paste')) {
                $table->text('pix_copy_paste')->nullable()->after('pix_qr_code');
            }
            if (!Schema::hasColumn('charges', 'pix_end2end_id')) {
                $table->string('pix_end2end_id')->nullable()->after('pix_copy_paste');
            }
            
            // Boleto fields
            if (!Schema::hasColumn('charges', 'boleto_url')) {
                $table->string('boleto_url')->nullable()->after('pix_end2end_id');
            }
            if (!Schema::hasColumn('charges', 'boleto_barcode')) {
                $table->string('boleto_barcode')->nullable()->after('boleto_url');
            }
            if (!Schema::hasColumn('charges', 'boleto_digitable_line')) {
                $table->string('boleto_digitable_line')->nullable()->after('boleto_barcode');
            }
            
            // General payment fields  
            if (!Schema::hasColumn('charges', 'payment_url')) {
                $table->string('payment_url')->nullable()->after('boleto_digitable_line');
            }
            if (!Schema::hasColumn('charges', 'acquirer')) {
                $table->string('acquirer')->nullable()->after('payment_url');
            }
            if (!Schema::hasColumn('charges', 'acquirer_transaction_id')) {
                $table->string('acquirer_transaction_id')->nullable()->after('acquirer');
            }
            
            // Refund/Status fields
            if (!Schema::hasColumn('charges', 'refunded_amount')) {
                $table->bigInteger('refunded_amount')->default(0)->after('acquirer_transaction_id');
            }
            if (!Schema::hasColumn('charges', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
            }
            if (!Schema::hasColumn('charges', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('refunded_at');
            }
            if (!Schema::hasColumn('charges', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('cancelled_at');
            }
            
            // Installments
            if (!Schema::hasColumn('charges', 'installments')) {
                $table->integer('installments')->default(1)->after('failure_reason');
            }
            
            // Description
            if (!Schema::hasColumn('charges', 'description')) {
                $table->string('description')->nullable()->after('installments');
            }
            
            // Fee info
            if (!Schema::hasColumn('charges', 'fee_amount')) {
                $table->bigInteger('fee_amount')->default(0)->after('description');
            }
            if (!Schema::hasColumn('charges', 'net_amount')) {
                $table->bigInteger('net_amount')->nullable()->after('fee_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn([
                'pix_qr_code',
                'pix_copy_paste', 
                'pix_end2end_id',
                'boleto_url',
                'boleto_barcode',
                'boleto_digitable_line',
                'payment_url',
                'acquirer',
                'acquirer_transaction_id',
                'refunded_amount',
                'refunded_at',
                'cancelled_at',
                'failure_reason',
                'installments',
                'description',
                'fee_amount',
                'net_amount',
            ]);
        });
    }
};
