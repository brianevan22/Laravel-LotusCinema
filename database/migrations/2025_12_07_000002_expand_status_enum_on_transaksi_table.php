<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transaksi') || !Schema::hasColumn('transaksi', 'status')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `transaksi` MODIFY `status` ENUM('pending','sukses','batal') NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
            // Fallback: ignore if database driver doesn't support ENUM change
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('transaksi') || !Schema::hasColumn('transaksi', 'status')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `transaksi` MODIFY `status` ENUM('pending','sukses') NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
