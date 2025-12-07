<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transaksi')) {
            return;
        }

        Schema::table('transaksi', function (Blueprint $table) {
            if (!Schema::hasColumn('transaksi', 'status')) {
                $table->string('status', 20)->default('pending')->after('total_harga');
            }
            if (!Schema::hasColumn('transaksi', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('status');
            }
            if (!Schema::hasColumn('transaksi', 'payment_destination')) {
                $table->string('payment_destination', 120)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('transaksi', 'payment_reference')) {
                $table->string('payment_reference', 120)->nullable()->after('payment_destination');
            }
            if (!Schema::hasColumn('transaksi', 'payment_note')) {
                $table->text('payment_note')->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('transaksi', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('payment_note');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaksi')) {
            return;
        }

        Schema::table('transaksi', function (Blueprint $table) {
            foreach ([
                'paid_at',
                'payment_note',
                'payment_reference',
                'payment_destination',
                'payment_method',
                'status',
            ] as $column) {
                if (Schema::hasColumn('transaksi', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
