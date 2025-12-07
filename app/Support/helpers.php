<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (!function_exists('table_has_col')) {
    function table_has_col(string $table, string $col): bool {
        try {
            return Schema::hasColumn($table, $col);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pick_auth_table')) {
    function pick_auth_table(): ?string {
        if (Schema::hasTable('users')) {
            return 'users';
        }
        if (Schema::hasTable('customer')) {
            return 'customer';
        }
        if (Schema::hasTable('pelanggan')) {
            return 'pelanggan';
        }
        return null;
    }
}

if (!function_exists('canonical_jadwal')) {
    function canonical_jadwal(int $jadwalId): array {
        $jd = DB::table('jadwal')->where('jadwal_id', $jadwalId)->first();
        if (!$jd) {
            return [null, [], null];
        }
        $ids = DB::table('jadwal')
            ->where('studio_id', $jd->studio_id)
            ->where('tanggal', $jd->tanggal)
            ->where('jam_mulai', $jd->jam_mulai)
            ->orderBy('jadwal_id')
            ->pluck('jadwal_id')
            ->all();
        if (empty($ids)) {
            return [null, [], null];
        }
        $canonId = (int) min($ids);
        return [$canonId, $ids, $jd];
    }
}

if (!function_exists('auth_pk_col')) {
    function auth_pk_col(string $table): string {
        foreach (['id_users', 'users_id', 'id', 'user_id', 'usersid', 'customer_id'] as $col) {
            if (table_has_col($table, $col)) {
                return $col;
            }
        }
        return 'id';
    }
}

if (!function_exists('table_smallest_missing_pk')) {
    function table_smallest_missing_pk(string $table, string $pk): int {
        $rows = DB::table($table)->pluck($pk)->toArray();
        $set = [];
        foreach ($rows as $v) {
            if (is_numeric($v)) {
                $set[(int) $v] = true;
            }
        }
        $i = 1;
        while (true) {
            if (!isset($set[$i])) {
                return $i;
            }
            $i++;
        }
    }
}

if (!function_exists('table_insert_with_pk')) {
    function table_insert_with_pk(string $table, array $data) {
        $pk = auth_pk_col($table);
        try {
            return DB::table($table)->insertGetId($data, $pk);
        } catch (\Throwable $e) {
            try {
                if (!array_key_exists($pk, $data)) {
                    $available = table_smallest_missing_pk($table, $pk);
                    $data[$pk] = $available;
                    DB::table($table)->insert($data);
                    return $data[$pk];
                }
            } catch (\Throwable $_) {
                if (!array_key_exists($pk, $data)) {
                    $max = DB::table($table)->max($pk);
                    $data[$pk] = (is_numeric($max) ? ((int) $max + 1) : 1);
                    DB::table($table)->insert($data);
                    return $data[$pk];
                }
            }
            throw $e;
        }
    }
}

if (!function_exists('ensure_admin_user')) {
    function ensure_admin_user(string $table): void {
        if (!table_has_col($table, 'username') || !table_has_col($table, 'password')) {
            return;
        }
        $pk = auth_pk_col($table);
        $admin = DB::table($table)->where('username', 'admin')->first();
        if (!$admin) {
            return;
        }
        $now = now();
        $base = [
            'username' => 'admin',
            'password' => Hash::make('admin123'),
        ];
        if (table_has_col($table, 'name')) {
            $base['name'] = 'Administrator';
        }
        if (table_has_col($table, 'email')) {
            $base['email'] = 'admin@bioskop.local';
        }
        if (table_has_col($table, 'role')) {
            $base['role'] = 'admin';
        }
        if (table_has_col($table, 'api_token')) {
            $base['api_token'] = Str::random(40);
        }
        if (table_has_col($table, 'created_at')) {
            $base['created_at'] = $now;
        }
        if (table_has_col($table, 'updated_at')) {
            $base['updated_at'] = $now;
        }

        $needsRole = table_has_col($table, 'role') && (($admin->role ?? '') !== 'admin');
        $needsToken = table_has_col($table, 'api_token') && empty($admin->api_token);
        if ($needsRole || $needsToken) {
            $updates = [];
            if ($needsRole) {
                $updates['role'] = 'admin';
            }
            if ($needsToken) {
                $updates['api_token'] = Str::random(40);
            }
            if (table_has_col($table, 'updated_at')) {
                $updates['updated_at'] = $now;
            }
            DB::table($table)->where($pk, $admin->{$pk})->update($updates);
        }
    }
}
