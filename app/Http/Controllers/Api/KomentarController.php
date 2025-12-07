<?php

namespace App\Http\Controllers\Api;

use App\Models\Komentar;
use App\Http\Resources\KomentarResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KomentarController extends \App\Http\Controllers\Controller
{
    // helper kecil untuk mendeteksi pk users (id_users atau id)
    protected function usersPk(): string {
        if (Schema::hasColumn('users','id_users')) return 'id_users';
        if (Schema::hasColumn('users','id')) return 'id';
        return 'id';
    }

    // Ambil user dari token Bearer di header. Kembalikan row user atau null.
    protected function userFromToken(Request $r) {
        $token = $r->bearerToken();
        if (!$token) {
            $token = $r->input('token') ?? $r->query('token');
        }
        if (!$token) {
            $auth = $r->header('Authorization') ?? $r->header('authorization') ?? '';
            if (!empty($auth)) {
                if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = trim($m[1]);
                else $token = trim($auth);
            }
        }
        if (!$token) return null;
        if (!Schema::hasTable('users')) return null;

        $pk = $this->usersPk();

        // 1) Jika users punya kolom api_token -> cari berdasarkan itu dulu
        if (Schema::hasColumn('users','api_token')) {
            $u = DB::table('users')->where('api_token', $token)->first();
            if ($u) return $u;
        }

        // 2) Jika token numeric -> coba cocokkan ke primary key users (id_users / id)
        if (is_numeric($token)) {
            $val = strpos($token, '.') !== false ? (int)floor(floatval($token)) : (int)$token;
            $u = DB::table('users')->where($pk, $val)->first();
            if ($u) return $u;
        }

        // 3) Coba cocokkan sebagai username atau name (fallback)
        $u = DB::table('users')->where('username', $token)->orWhere('name', $token)->first();
        if ($u) return $u;

        // tidak ketemu
        return null;
    }

    /**
     * GET /api/komentar
     * optional query: film_id, sort=newest|oldest
     */
    public function index(Request $request)
    {
        $filmId = $request->query('film_id');
        $sort = strtolower($request->query('sort', 'newest'));

        $q = DB::table('komentar as k');

        // join users if exists to fetch commenter name
        if (Schema::hasTable('users')) {
            $pk = $this->usersPk();
            $q->leftJoin('users as u', "u.$pk", '=', 'k.users_id');
            $select = ['k.*', DB::raw("COALESCE(u.name, u.username, '') as commenter_name")];
            // tambahkan kolom user yang mungkin ada agar bisa dibawa ke client sebagai profile
            $select[] = DB::raw("u.$pk as commenter_uid");
            if (Schema::hasColumn('users','username')) $select[] = DB::raw('u.username as commenter_username');
            if (Schema::hasColumn('users','role'))     $select[] = DB::raw('u.role as commenter_role');
            if (Schema::hasColumn('users','email'))    $select[] = DB::raw('u.email as commenter_email');
            $q->select($select);
        } else {
            $q->select(['k.*', DB::raw("'' as commenter_name"), DB::raw("NULL as commenter_uid"), DB::raw("'' as commenter_username"), DB::raw("'' as commenter_role"), DB::raw("'' as commenter_email")]);
        }

        if ($filmId !== null && $filmId !== '') {
            $q->where('k.film_id', (int)$filmId);
        }

        if ($sort === 'oldest') {
            $q->orderBy('k.komentar_id', 'asc');
            $q->orderBy('k.tanggal','asc');
        } else {
            $q->orderBy('k.tanggal', 'desc');
            $q->orderBy('k.komentar_id', 'desc');
        }

        $rows = $q->get()->map(function($r) {
            // normalisasi tanggal
            $r->tanggal = (string)($r->tanggal ?? '');
            $r->commenter_name = (string)($r->commenter_name ?? ($r->nama ?? ''));
            // bangun objek profile pengguna yang konsisten (hanya nama + is_admin)
            $roleVal = (string)($r->commenter_role ?? '');
            $usernameVal = (string)($r->commenter_username ?? '');
            $nameVal = (string)($r->commenter_name ?? '');
            $isAdmin = (strtolower($roleVal) === 'admin' || strtolower($usernameVal) === 'admin');
            $display = $nameVal . ($isAdmin ? ' (admin)' : '');
            $r->commenter_profile = [
                'id'           => isset($r->commenter_uid) ? (int)$r->commenter_uid : null,
                'name'         => $nameVal,
                'display_name' => $display,
                'is_admin'     => $isAdmin,
                'role'         => $roleVal,
            ];
            // flag edited (jika tabel komentar menyimpan updated_at)
            $r->edited = false;
            if (Schema::hasColumn('komentar','updated_at')) {
                $r->edited = !empty($r->updated_at);
            }
            return $r;
        });

        return response()->json($rows->values());
    }

    /**
     * POST /api/komentar
     * body: film_id (required), isi_komentar (required), rating (nullable 1..5)
     * users_id akan diisi dari api_token header
     */
    public function store(Request $request)
    {
        $request->validate([
            'film_id' => 'required|integer',
            'isi_komentar' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $user = $this->userFromToken($request);
        if (!$user) return response()->json(['message' => 'Unauthorized: token invalid atau tidak ada'], 401);

        // tentukan user id numeric sesuai PK pengguna
        $pk = $this->usersPk();
        $userId = $user->{$pk};

        $ins = [
            'users_id' => $userId,
            'film_id' => (int)$request->input('film_id'),
            'isi_komentar' => $request->input('isi_komentar'),
            'rating' => $request->input('rating'),
            'tanggal' => date('Y-m-d'),
        ];

        $id = DB::table('komentar')->insertGetId($ins);
        // build select columns conditionally (users.email mungkin tidak ada)
        $select = ['k.*', DB::raw("COALESCE(u.name, u.username, '') as commenter_name")];
        $select[] = DB::raw("u.$pk as commenter_uid");
        if (Schema::hasColumn('users','username')) $select[] = DB::raw('u.username as commenter_username');
        if (Schema::hasColumn('users','role'))     $select[] = DB::raw('u.role as commenter_role');
        if (Schema::hasColumn('users','email'))    $select[] = DB::raw('u.email as commenter_email');
        $row = DB::table('komentar as k')
            ->leftJoin('users as u', $pk, '=', 'k.users_id')
            ->where('k.komentar_id', $id)
            ->select($select)
            ->first();
        // tambahkan commenter_profile pada response created (tampilkan admin label jika perlu) dan edited flag
        if ($row) {
            $roleVal = (string)($row->commenter_role ?? '');
            $usernameVal = (string)($row->commenter_username ?? '');
            $nameVal = (string)($row->commenter_name ?? '');
            $isAdmin = (strtolower($roleVal) === 'admin' || strtolower($usernameVal) === 'admin');
            $display = $nameVal . ($isAdmin ? ' (admin)' : '');
            $row->commenter_profile = [
                'id' => isset($row->commenter_uid) ? (int)$row->commenter_uid : null,
                'name' => $nameVal,
                'display_name' => $display,
                'is_admin' => $isAdmin,
                'role' => $roleVal,
            ];
            $row->edited = false;
            if (Schema::hasColumn('komentar','updated_at')) {
                $row->edited = !empty($row->updated_at);
            }
        }
        return response()->json($row, 201);
    }

    /**
     * PUT /api/komentar/{id}
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'isi_komentar' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $user = $this->userFromToken($request);
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        $pk = $this->usersPk();
        $userId = $user->{$pk};

        $kom = DB::table('komentar')->where('komentar_id', (int)$id)->first();
        if (!$kom) return response()->json(['message' => 'Komentar tidak ditemukan'], 404);
        
        // Hanya pemilik komentar yang boleh mengedit
        if ((int)$kom->users_id !== (int)$userId) {
            return response()->json(['message' => 'Anda tidak berhak mengedit komentar ini'], 403);
        }

        $upd = [
            'isi_komentar' => $request->input('isi_komentar'),
            'rating' => $request->input('rating'),
            // jangan ubah tanggal (tetap tanggal asli), tapi boleh tambahkan updated_at jika ada
        ];
        if (Schema::hasColumn('komentar','updated_at')) $upd['updated_at'] = now();

        DB::table('komentar')->where('komentar_id', (int)$id)->update($upd);

        // same conditional select for update response
        $select = ['k.*', DB::raw("COALESCE(u.name, u.username, '') as commenter_name")];
        $select[] = DB::raw("u.$pk as commenter_uid");
        if (Schema::hasColumn('users','username')) $select[] = DB::raw('u.username as commenter_username');
        if (Schema::hasColumn('users','role'))     $select[] = DB::raw('u.role as commenter_role');
        if (Schema::hasColumn('users','email'))    $select[] = DB::raw('u.email as commenter_email');
        $row = DB::table('komentar as k')
            ->leftJoin('users as u', $pk, '=', 'k.users_id')
            ->where('k.komentar_id', (int)$id)
            ->select($select)
            ->first();
        if ($row) {
            $roleVal = (string)($row->commenter_role ?? '');
            $usernameVal = (string)($row->commenter_username ?? '');
            $nameVal = (string)($row->commenter_name ?? '');
            $isAdmin = (strtolower($roleVal) === 'admin' || strtolower($usernameVal) === 'admin');
            $display = $nameVal . ($isAdmin ? ' (admin)' : '');
            $row->commenter_profile = [
                'id' => isset($row->commenter_uid) ? (int)$row->commenter_uid : null,
                'name' => $nameVal,
                'display_name' => $display,
                'is_admin' => $isAdmin,
                'role' => $roleVal,
            ];
            $row->edited = false;
            if (Schema::hasColumn('komentar','updated_at')) {
                $row->edited = !empty($row->updated_at);
            }
        }
        return response()->json($row);
    }

    /**
     * DELETE /api/komentar/{id}
     * Perbaikan: Mengizinkan ADMIN untuk menghapus komentar apa saja
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->userFromToken($request);
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        $pk = $this->usersPk();
        $userId = $user->{$pk};

        $kom = DB::table('komentar')->where('komentar_id', (int)$id)->first();
        if (!$kom) return response()->json(['message' => 'Komentar tidak ditemukan'], 404);

        // Cek apakah user adalah admin
        $isAdmin = false;
        if (isset($user->role) && strtolower($user->role) === 'admin') $isAdmin = true;
        if (isset($user->username) && strtolower($user->username) === 'admin') $isAdmin = true;

        // Logika penghapusan:
        // 1. Jika user adalah pemilik komentar -> BOLEH
        // 2. Jika user adalah ADMIN -> BOLEH
        // 3. Jika bukan keduanya -> DITOLAK
        
        $isOwner = ((int)$kom->users_id === (int)$userId);

        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Anda tidak berhak menghapus komentar ini'], 403);
        }

        DB::table('komentar')->where('komentar_id', (int)$id)->delete();
        return response()->json(['deleted' => true, 'message' => 'Komentar berhasil dihapus']);
    }
}