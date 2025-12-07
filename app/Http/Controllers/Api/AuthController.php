<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    protected function userPk(): string
    {
        foreach (['id_users', 'user_id', 'id'] as $col) {
            if (Schema::hasColumn('users', $col)) {
                return $col;
            }
        }
        return 'id';
    }

    protected function customerPk(): string
    {
        foreach (['customer_id', 'id'] as $col) {
            if (Schema::hasColumn('customer', $col)) {
                return $col;
            }
        }
        return 'customer_id';
    }

    protected function buildUserPayload(object $user, ?int $customerId = null): array
    {
        $pk = $this->userPk();
        return [
            'id' => (int) ($user->{$pk} ?? 0),
            'name' => $user->name ?? null,
            'username' => $user->username ?? null,
            'role' => $user->role ?? 'customer',
            'customer_id' => $customerId,
            'created_at' => $user->created_at ?? null,
            'updated_at' => $user->updated_at ?? null,
        ];
    }

    protected function resolveCustomerId(int $userId): ?int
    {
        if (!Schema::hasTable('customer')) {
            return null;
        }

        $linkCol = Schema::hasColumn('customer', 'id_users') ? 'id_users' : $this->customerPk();
        $row = DB::table('customer')->where($linkCol, $userId)->first();
        if ($row) {
            $pk = $this->customerPk();
            return isset($row->{$pk}) ? (int) $row->{$pk} : null;
        }

        $pk = $this->customerPk();
        $fallback = DB::table('customer')->where($pk, $userId)->first();
        return $fallback ? (int) $fallback->{$pk} : null;
    }

    public function login(Request $request)
    {
        if (!Schema::hasTable('users')) {
            return response()->json(['message' => 'Tabel users tidak ditemukan'], 500);
        }

        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $credential = trim($data['username']);
        $password = $data['password'];

        $user = DB::table('users')
            ->where('username', $credential)
            ->orWhere('name', $credential)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Username atau password salah'], 401);
        }

        $pk = $this->userPk();
        $userId = (int) ($user->{$pk} ?? 0);
        $customerId = $this->resolveCustomerId($userId);

        return response()->json([
            'ok' => true,
            'token' => (string) $userId,
            'role' => $user->role ?? 'customer',
            'user' => $this->buildUserPayload($user, $customerId),
            'customer_id' => $customerId,
        ]);
    }

    public function register(Request $request)
    {
        if (!Schema::hasTable('users')) {
            return response()->json(['message' => 'Tabel users tidak ditemukan'], 500);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100'],
            'no_hp' => ['nullable', 'string', 'max:20'],
        ]);

        if (
            Schema::hasTable('customer') &&
            Schema::hasColumn('customer', 'email') &&
            DB::table('customer')->where('email', $data['email'])->exists()
        ) {
            return response()->json(['message' => 'Email sudah terdaftar'], 409);
        }

        $now = now();
        $pk = $this->userPk();

        return DB::transaction(function () use ($data, $now, $pk) {
            $userId = DB::table('users')->insertGetId([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'role' => 'customer',
                'created_at' => $now,
                'updated_at' => $now,
            ], $pk);

            $customerId = null;
            if (Schema::hasTable('customer')) {
                $customerInsert = [
                    'id_users' => $userId,
                    'nama' => $data['name'],
                    'email' => $data['email'],
                    'no_hp' => $data['no_hp'] ?? null,
                ];

                $customerId = DB::table('customer')->insertGetId(
                    $customerInsert,
                    $this->customerPk()
                );
            }

            $user = DB::table('users')->where($pk, $userId)->first();

            return response()->json([
                'ok' => true,
                'message' => 'Registrasi berhasil',
                'token' => (string) $userId,
                'role' => 'customer',
                'user' => $user ? $this->buildUserPayload($user, $customerId) : null,
                'customer_id' => $customerId,
            ], 201);
        });
    }

    public function logout()
    {
        return response()->json(['ok' => true]);
    }
}
