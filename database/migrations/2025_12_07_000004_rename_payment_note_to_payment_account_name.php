<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('transaksi')) {
            return;
        }

        if (!Schema::hasColumn('transaksi', 'payment_note')) {
            return;
        }

        DB::statement("ALTER TABLE `transaksi` CHANGE `payment_note` `payment_account_name` VARCHAR(150) NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaksi')) {
            return;
        }

        if (!Schema::hasColumn('transaksi', 'payment_account_name')) {
            return;
        }

        DB::statement("ALTER TABLE `transaksi` CHANGE `payment_account_name` `payment_note` TEXT NULL");
    }
};
