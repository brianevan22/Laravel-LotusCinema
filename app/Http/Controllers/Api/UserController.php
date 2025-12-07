<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected string $table = 'users';

    protected function pk(): string
    {
        // Deteksi nama primary key yang umum (prioritaskan id_users)
        if (Schema::hasColumn($this->table, 'id_users')) return 'id_users';
        if (Schema::hasColumn($this->table, 'id')) return 'id';
        if (Schema::hasColumn($this->table, 'user_id')) return 'user_id';
        return 'id';
    }

    protected function hasCol(string $col): bool
    {
        try { return Schema::hasColumn($this->table, $col); }
        catch (\Throwable $e) { return false; }
    }

    /**
     * Sync customer table according to user id and role:
     * - jika $role == 'customer' -> pastikan ada record di tabel customer dengan customer_id == $userId
     *   gunakan $name/$email/$noHp bila diberikan (fallback ke users table)
     * - jika $role == 'admin' -> hapus record customer dengan id tersebut (jika ada)
     */
    protected function syncCustomer(int $userId, string $role, ?string $name = null, ?string $email = null, ?string $noHp = null): void
    {
        if (!Schema::hasTable('customer')) {
            return;
        }

        $userRow = DB::table($this->table)->where($this->pk(), $userId)->first();
        $customer = DB::table('customer')->where('id_users', $userId)->first();

        if ($role !== 'customer') {
            if ($customer) {
                DB::table('customer')->where('customer_id', $customer->customer_id)->delete();
            }
            return;
        }

        $payload = [];
        $resolvedName = $name ?? ($userRow->name ?? null);
        if ($resolvedName && Schema::hasColumn('customer', 'nama')) {
            $payload['nama'] = $resolvedName;
        }
        if ($email && Schema::hasColumn('customer', 'email')) {
            $payload['email'] = $email;
        }
        if ($noHp && Schema::hasColumn('customer', 'no_hp')) {
            $payload['no_hp'] = $noHp;
        }

        if ($customer) {
            if (!empty($payload)) {
                if (Schema::hasColumn('customer', 'updated_at')) {
                    $payload['updated_at'] = now();
                }
                DB::table('customer')
                    ->where('customer_id', $customer->customer_id)
                    ->update($payload);
            }
            return;
        }

        if (Schema::hasColumn('customer', 'email') && empty($payload['email'])) {
            return;
        }

        $insert = ['id_users' => $userId] + $payload;
        if (Schema::hasColumn('customer', 'created_at')) {
            $insert['created_at'] = now();
        }
        if (Schema::hasColumn('customer', 'updated_at')) {
            $insert['updated_at'] = now();
        }

        DB::table('customer')->insert($insert);
    }

    /**
     * GET /api/users?search=&per_page=
     */
    public function index(Request $r)
    {
        $perPage = (int) ($r->query('per_page', 15));
        $perPage = $perPage > 0 ? $perPage : 15;

        $q = DB::table($this->table);

        if ($search = $r->query('search')) {
            $q->where(function ($builder) use ($search) {
                if ($this->hasCol('username')) {
                    $builder->orWhere('username', 'like', "%$search%");
                }
                if ($this->hasCol('name')) {
                    $builder->orWhere('name', 'like', "%$search%");
                }
            });
        }

        return $q->orderBy($this->pk(), 'desc')->paginate($perPage);
    }

    /**
     * GET /api/users/{id}
     */
    public function show($id)
    {
        $row = DB::table($this->table)->where($this->pk(), $id)->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json($row);
    }

    /**
     * POST /api/users
     * body: { username(required), password(required), email(optional), name(optional) }
     * Extended: create customer row with same id if role=customer (or default)
     */
    public function store(Request $r)
    {
        $emailRule = ['nullable', 'email'];
        if ($this->hasCol('email')) {
            $emailRule[] = Rule::unique($this->table, 'email');
        }

        $rules = [
            'username' => ['required', 'string', Rule::unique($this->table, 'username')],
            'password' => ['required', 'string', 'min:4'],
            'name'     => ['nullable', 'string'],
            'role'     => ['nullable', 'string', Rule::in(['admin', 'customer'])],
            'email'    => $emailRule,
            'no_hp'    => ['nullable', 'string'],
        ];
        $messages = [
            'username.unique' => 'Username sudah digunakan, silakan pilih username lain.',
            'email.unique'    => 'Email sudah terdaftar, silakan gunakan email lain.',
        ];
        $r->validate($rules, $messages);

        $data = [];
        if ($this->hasCol('username')) {
            $data['username'] = $r->input('username');
        }
        if ($this->hasCol('name') && $r->filled('name')) {
            $data['name'] = $r->input('name');
        }
        if ($this->hasCol('password')) {
            $data['password'] = Hash::make($r->input('password'));
        }
        if ($this->hasCol('role')) {
            $data['role'] = $r->input('role', 'customer');
        }
        if ($this->hasCol('created_at')) {
            $data['created_at'] = now();
        }
        if ($this->hasCol('updated_at')) {
            $data['updated_at'] = now();
        }

        $id = DB::table($this->table)->insertGetId($data);
        $role = $data['role'] ?? 'customer';

        $this->syncCustomer(
            $id,
            $role,
            $r->input('name'),
            $r->input('email'),
            $r->input('no_hp')
        );

        $row = DB::table($this->table)->where($this->pk(), $id)->first();
        return response()->json($row, 201);
    }

    /**
     * PUT /api/users/{id}
     */
    public function update(Request $r, $id)
    {
        $emailRule = ['nullable', 'email'];
        if ($this->hasCol('email')) {
            $emailRule[] = Rule::unique($this->table, 'email')->ignore($id, $this->pk());
        }

        $rules = [
            'username' => ['nullable', 'string', Rule::unique($this->table, 'username')->ignore($id, $this->pk())],
            'name'     => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:4'],
            'role'     => ['nullable', 'string', Rule::in(['admin', 'customer'])],
            'email'    => $emailRule,
            'no_hp'    => ['nullable', 'string'],
        ];
        $messages = [
            'username.unique' => 'Username sudah digunakan, silakan pilih username lain.',
            'email.unique'    => 'Email sudah terdaftar, silakan gunakan email lain.',
        ];
        $r->validate($rules, $messages);

        $data = [];
        if ($this->hasCol('username') && $r->filled('username')) {
            $data['username'] = $r->input('username');
        }
        if ($this->hasCol('name') && $r->filled('name')) {
            $data['name'] = $r->input('name');
        }
        if ($this->hasCol('password') && $r->filled('password')) {
            $data['password'] = Hash::make($r->input('password'));
        }
        if ($this->hasCol('role') && $r->filled('role')) {
            $data['role'] = $r->input('role');
        }
        if ($this->hasCol('updated_at')) {
            $data['updated_at'] = now();
        }

        if (!empty($data)) {
            DB::table($this->table)->where($this->pk(), $id)->update($data);
        }

        $fresh = DB::table($this->table)->where($this->pk(), $id)->first();
        if (!$fresh) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->syncCustomer(
            (int) $id,
            $fresh->role ?? 'customer',
            $r->filled('name') ? $r->input('name') : ($fresh->name ?? null),
            $r->input('email'),
            $r->input('no_hp')
        );

        return response()->json($fresh);
    }

    /**
     * DELETE /api/users/{id}
     */
    public function destroy($id)
    {
        if (Schema::hasTable('customer')) {
            DB::table('customer')->where('id_users', (int) $id)->delete();
        }
        $deleted = DB::table($this->table)->where($this->pk(), $id)->delete();
        return response()->json(['deleted' => (bool) $deleted]);
    }
}
