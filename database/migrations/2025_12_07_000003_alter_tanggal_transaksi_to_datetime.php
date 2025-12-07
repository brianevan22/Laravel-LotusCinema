<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transaksi') || !Schema::hasColumn('transaksi', 'tanggal_transaksi')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `transaksi` MODIFY `tanggal_transaksi` DATETIME NULL DEFAULT NULL");
        } catch (\Throwable $e) {
            // ignore if DB engine doesn't support MODIFY
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaksi') || !Schema::hasColumn('transaksi', 'tanggal_transaksi')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `transaksi` MODIFY `tanggal_transaksi` DATE NULL DEFAULT NULL");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
