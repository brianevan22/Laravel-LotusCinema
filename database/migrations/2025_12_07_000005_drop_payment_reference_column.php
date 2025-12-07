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

        if (Schema::hasColumn('transaksi', 'payment_reference')) {
            DB::statement("ALTER TABLE `transaksi` DROP COLUMN `payment_reference`");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaksi')) {
            return;
        }

        if (!Schema::hasColumn('transaksi', 'payment_reference')) {
            DB::statement("ALTER TABLE `transaksi` ADD `payment_reference` VARCHAR(120) NULL AFTER `payment_destination`");
        }
    }
};
