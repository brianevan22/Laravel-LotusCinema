<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Carbon\Carbon;

use App\Http\Controllers\Api\{
    AuthController,
    FilmController,
    GenreController,
    JadwalController,
    TiketController,
    TransaksiController,
    KasirController,
    CustomerController,
    DetailTransaksiController,
    KomentarController,
    KursiController,
    StudioController,
    UserController
};

/* ---------- helpers kecil ---------- */
function table_has_col(string $table, string $col): bool {
    try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
}
function pick_auth_table(): ?string {
    if (Schema::hasTable('users'))    return 'users';
    if (Schema::hasTable('customer')) return 'customer';
    if (Schema::hasTable('pelanggan'))return 'pelanggan';
    return null;
}
/** Cari jadwal kanonik untuk slot (studio+tanggal+jam_mulai) */
function canonical_jadwal(int $jadwalId): array {
    $jd = DB::table('jadwal')->where('jadwal_id',$jadwalId)->first();
    if (!$jd) return [null, [], null];
    $ids = DB::table('jadwal')
        ->where('studio_id', $jd->studio_id)
        ->where('tanggal',   $jd->tanggal)
        ->where('jam_mulai', $jd->jam_mulai)
        ->orderBy('jadwal_id')
        ->pluck('jadwal_id')->all();
    if (empty($ids)) return [null, [], null];
    $canonId = (int)min($ids);
    return [$canonId, $ids, $jd];
}

/* helper tambahan */
function auth_pk_col(string $table): string {
    // Prioritas cek nama kolom PK yang mungkin dipakai oleh project:
    foreach (['id_users','users_id','id','user_id','usersid','customer_id'] as $col) {
        if (table_has_col($table, $col)) return $col;
    }
    return 'id';
}

function table_smallest_missing_pk(string $table, string $pk): int {
    // cari nilai integer terkecil >0 yang belum ada di kolom $pk
    $rows = DB::table($table)->pluck($pk)->toArray();
    $set = [];
    foreach ($rows as $v) {
        if (is_numeric($v)) $set[(int)$v] = true;
    }
    $i = 1;
    while (true) {
        if (!isset($set[$i])) return $i;
        $i++;
    }
}

function table_insert_with_pk(string $table, array $data) {
    $pk = auth_pk_col($table);
    try {
        return DB::table($table)->insertGetId($data, $pk);
    } catch (\Throwable $e) {
        // jika gagal karena kolom PK tidak auto increment / butuh explicit value,
        // coba isi dengan smallest missing PK (mengisi gap)
        try {
            if (!array_key_exists($pk, $data)) {
                $available = table_smallest_missing_pk($table, $pk);
                $data[$pk] = $available;
                DB::table($table)->insert($data);
                return $data[$pk];
            }
        } catch (\Throwable $_) {
            // fallback lama: next pk (max+1)
            if (!array_key_exists($pk, $data)) {
                $max = DB::table($table)->max($pk);
                $data[$pk] = (is_numeric($max) ? ((int)$max + 1) : 1);
                DB::table($table)->insert($data);
                return $data[$pk];
            }
        }
        throw $e;
    }
}
function ensure_admin_user(string $table): void {
    if (!table_has_col($table,'username') || !table_has_col($table,'password')) return;
    $pk = auth_pk_col($table);
    $admin = DB::table($table)->where('username','admin')->first();
    if (!$admin) {
        // tidak membuat akun baru; biarkan login gagal jika user tidak ada
        return;
    }
    $now = now();
    $base = [
        'username' => 'admin',
        'password' => Hash::make('admin123'),
    ];
    if (table_has_col($table,'name'))  $base['name'] = 'Administrator';
    if (table_has_col($table,'email')) $base['email'] = 'admin@bioskop.local';
    if (table_has_col($table,'role'))  $base['role'] = 'admin';
    if (table_has_col($table,'api_token')) $base['api_token'] = Str::random(40);
    if (table_has_col($table,'created_at')) $base['created_at'] = $now;
    if (table_has_col($table,'updated_at')) $base['updated_at'] = $now;

    $needsRole = table_has_col($table,'role') && ($admin->role ?? '') !== 'admin';
    $needsToken = table_has_col($table,'api_token') && empty($admin->api_token);
    if ($needsRole || $needsToken) {
        $updates = [];
        if ($needsRole)  $updates['role'] = 'admin';
        if ($needsToken) $updates['api_token'] = Str::random(40);
        if (table_has_col($table,'updated_at')) $updates['updated_at'] = $now;
        DB::table($table)->where($pk, $admin->{$pk})->update($updates);
    }
}

/* ---------- root & ping ---------- */
Route::get('/',     fn() => response()->json(['message' => 'API Bioskop Laravel aktif 🚀']));
Route::get('/ping', fn() => response()->json(['pong' => now()->toIso8601String()]));

/* ---------- AUTH ---------- */
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

/* ---------- GENRES (fallback) ---------- */
function _pick_genre_table() {
    foreach (['genres', 'genre', 'tbl_genre'] as $t) if (Schema::hasTable($t)) return $t;
    return null;
}
function _genre_id_col($t) {
    foreach (['genre_id', 'id', 'id_genre'] as $c) if (Schema::hasColumn($t, $c)) return $c;
    return 'id';
}
function _genre_name_col($t) {
    foreach (['nama', 'nama_genre', 'name', 'judul', 'title'] as $c) if (Schema::hasColumn($t, $c)) return $c;
    return 'nama';
}
Route::get('/genres', function () {
    $t = _pick_genre_table();
    if (!$t) return response()->json(['message' => 'Tabel genre tidak ditemukan'], 500);
    $id  = _genre_id_col($t);
    $nam = _genre_name_col($t);
    $rows = DB::table($t)->select([$id.' as id', $nam.' as nama'])->orderBy($id)->get();
    return response()->json($rows);
});
Route::get('/genres/{id}', function ($id) {
    $t = _pick_genre_table();
    if (!$t) return response()->json(['message' => 'Tabel genre tidak ditemukan'], 500);
    $idCol  = _genre_id_col($t);
    $namCol = _genre_name_col($t);
    $row = DB::table($t)->where($idCol, $id)->select([$idCol.' as id', $namCol.' as nama'])->first();
    if (!$row) return response()->json(['message' => 'Genre tidak ditemukan'], 404);
    return response()->json($row);
});

/* ---------- FILM ---------- */
Route::get('/film',         [FilmController::class, 'index']);
Route::post('/film',        [FilmController::class, 'store']);
Route::get('/film/{id}',    [FilmController::class, 'show']);
Route::put('/film/{id}',    [FilmController::class, 'update']);
Route::delete('/film/{id}', [FilmController::class, 'destroy']);

Route::get('/films',         [FilmController::class, 'index']);
Route::post('/films',        [FilmController::class, 'store']);
Route::get('/films/{id}',    [FilmController::class, 'show']);
Route::put('/films/{id}',    [FilmController::class, 'update']);
Route::delete('/films/{id}', [FilmController::class, 'destroy']);

Route::get('/customer', function (Request $request) {
	$request->merge(['flat' => true]);
	return app(CustomerController::class)->index($request);
});

/* ---------- JADWAL & KURSI ---------- */
Route::get('/jadwal',                         [JadwalController::class, 'index']);
Route::post('/jadwal',                        [JadwalController::class, 'store']);
Route::get('/jadwal/by-film/{film}',          [JadwalController::class, 'getByFilm']);
Route::get('/jadwal/film/{film}',             [JadwalController::class, 'getByFilm']); // alias
Route::get('/jadwal/{jadwal}/seats',          [JadwalController::class, 'getSeats']);
Route::get('/jadwal/{jadwal}/kursi-tersedia', [JadwalController::class, 'getSeats']);  // alias

// SHOW (tetap)
Route::get('/jadwal/{id}', function ($id) {
    $row = DB::table('jadwal as j')
        ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
        ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
        ->where('j.jadwal_id', (int)$id)
        ->select([
            'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
            DB::raw('f.judul as film_judul'),
            DB::raw('s.nama_studio as nama_studio'),
        ])->first();

    if (!$row) return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);
    return response()->json($row);
})->whereNumber('id');

// UPDATE (tetap)
Route::put('/jadwal/{id}', function (Request $r, $id) {
    $id = (int)$id;
    if (!DB::table('jadwal')->where('jadwal_id',$id)->exists()) {
        return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);
    }

    $r->validate([
        'film_id'     => 'required|integer',
        'studio_id'   => 'required|integer',
        'tanggal'     => 'required|date',
        'jam_mulai'   => 'required|date_format:H:i:s',
        'jam_selesai' => 'required|date_format:H:i:s',
    ]);

    $upd = $r->only(['film_id','studio_id','tanggal','jam_mulai','jam_selesai']);
    if (table_has_col('jadwal','updated_at')) $upd['updated_at'] = now();

    DB::table('jadwal')->where('jadwal_id',$id)->update($upd);

    $row = DB::table('jadwal as j')
        ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
        ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
        ->where('j.jadwal_id',$id)
        ->select([
            'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
            DB::raw('f.judul as film_judul'),
            DB::raw('s.nama_studio as nama_studio'),
        ])->first();

    return response()->json($row);
})->whereNumber('id');

/* ---------- KOMENTAR ---------- */
Route::get('/komentar',             [KomentarController::class, 'index']);
Route::post('/komentar',            [KomentarController::class, 'store']);
Route::delete('/komentar/{id}', function (\Illuminate\Http\Request $request, $id) {
    return app(\App\Http\Controllers\Api\KomentarController::class)->destroy($request, $id);
}); // Ini yang Anda butuhkan untuk hapus

// DELETE (mendukung sinkron slot)
Route::delete('/jadwal/{id}', [JadwalController::class, 'destroy'])->whereNumber('id');

/* ---------- CHECKOUT (pakai jadwal KANONIK) ---------- */
Route::post('/checkout', function (Request $r) {
    $r->validate([
        'customer_id' => 'required|integer',
        'jadwal_id'   => 'required|integer',
        'kursi_ids'   => 'required|array|min:1',
        'kursi_ids.*' => 'integer',
        'kasir_id'    => 'nullable|integer',
        'client_time' => 'nullable|string', // optional ISO8601 dari klien
        'client_tz'   => 'nullable|string', // optional timezone klien
        'payment_method' => 'nullable|string|max:50',
        'payment_destination' => 'nullable|string|max:120',
        'payment_account_name' => 'required|string|max:150',
    ]);

    // INPUT AWAL
    $inputCustomer = (int)$r->input('customer_id'); // bisa berupa users.id_users OR customer.customer_id
    $jadwalId      = (int)$r->input('jadwal_id');
    $ids           = $r->input('kursi_ids');
    $kasirIdReq    = $r->input('kasir_id');

    $resolveToken = function () use ($r) {
        $token = $r->bearerToken();
        if (!$token) $token = $r->input('token') ?? $r->query('token');
        if (!$token) {
            $authHeader = $r->header('Authorization') ?? $r->header('authorization') ?? '';
            if (!empty($authHeader)) {
                if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) $token = trim($m[1]);
                else $token = trim($authHeader);
            }
        }
        return $token;
    };
    $requestToken = $resolveToken();

    // =========================================================
    // Mapping customer: pastikan kita punya customer.customer_id yang valid
    // - Coba beberapa pendekatan agar input (yang mungkin users.id_users atau customer.customer_id)
    //   akan berhasil dipetakan ke customer.customer_id yang menjadi FK transaksi.
    $customerForTrans = null;
    if (Schema::hasTable('customer')) {
        // 1) jika ada kolom id_users di customer, coba cari dengan id_users == input
        if (Schema::hasColumn('customer', 'id_users')) {
            $row = DB::table('customer')->where('id_users', $inputCustomer)->first();
            if ($row) {
                $customerForTrans = (int)($row->customer_id ?? $row->id ?? null);
            }
        }
        // 2) jika belum ketemu, coba anggap input memang customer_id (legacy)
        if ($customerForTrans === null) {
            $row2 = DB::table('customer')->where('customer_id', $inputCustomer)->first();
            if ($row2) $customerForTrans = (int)$row2->customer_id;
        }
    }

    // 3) Jika belum ketemu, coba dari Authorization: jika ada Bearer token -> cari users by api_token,
    //    lalu mapping ke customer via customer.id_users atau customer.customer_id
    if ($customerForTrans === null) {
        $authHeader = $r->header('Authorization') ?? $r->header('authorization') ?? '';
        $token = null;
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) $token = $m[1];
            else $token = $authHeader;
        }
        if ($token && Schema::hasTable('users') && Schema::hasColumn('users','api_token')) {
            $userByToken = DB::table('users')->where('api_token', $token)->first();
            if ($userByToken) {
                $pkUser = auth_pk_col('users');
                $uId = $userByToken->{$pkUser};
                if (Schema::hasTable('customer')) {
                    if (Schema::hasColumn('customer', 'id_users')) {
                        $c = DB::table('customer')->where('id_users', $uId)->first();
                        if ($c) $customerForTrans = (int)$c->customer_id;
                    }
                    if ($customerForTrans === null) {
                        $c2 = DB::table('customer')->where('customer_id', $uId)->first();
                        if ($c2) $customerForTrans = (int)$c2->customer_id;
                    }
                } else {
                    // fallback: jika tidak ada tabel customer, gunakan user id (risiko FK)
                    $customerForTrans = (int)$uId;
                }
            }
        }
    }

    // 4) Jika masih belum, coba cek apakah input sama dengan users PK (mis. klien kirim users.id_users)
    if ($customerForTrans === null && Schema::hasTable('users')) {
        $pkUser = auth_pk_col('users');
        $userByPk = DB::table('users')->where($pkUser, $inputCustomer)->first();
        if ($userByPk) {
            $uId = $userByPk->{$pkUser};
            if (Schema::hasTable('customer')) {
                if (Schema::hasColumn('customer','id_users')) {
                    $c = DB::table('customer')->where('id_users', $uId)->first();
                    if ($c) $customerForTrans = (int)$c->customer_id;
                }
                if ($customerForTrans === null) {
                    $c2 = DB::table('customer')->where('customer_id', $uId)->first();
                    if ($c2) $customerForTrans = (int)$c2->customer_id;
                }
            } else {
                $customerForTrans = (int)$uId;
            }
        }
    }

    if ($customerForTrans === null) {
        return response()->json([
            'message' => 'Customer tidak ditemukan. Pastikan Anda menggunakan customer_id yang valid atau akun sudah tersinkron (login/register).'
        ], 422);
    }
    // =========================================================

    // === Prevent admin from performing checkout ===
    try {
        if ($requestToken && Schema::hasTable('users') && Schema::hasColumn('users','api_token')) {
            $u = DB::table('users')->where('api_token', $requestToken)->first();
            if ($u && isset($u->role) && strtolower($u->role) === 'admin') {
                return response()->json(['message' => 'Admin tidak boleh melakukan checkout'], 403);
            }
        }

        if ($customerForTrans !== null && Schema::hasTable('customer') && Schema::hasTable('users')) {
            $cust = DB::table('customer')->where('customer_id', $customerForTrans)->first();
            if ($cust) {
                if (Schema::hasColumn('customer','id_users') && !empty($cust->id_users)) {
                    $linkedUser = DB::table('users')->where(auth_pk_col('users'), $cust->id_users)->first();
                    if ($linkedUser && (isset($linkedUser->role) && strtolower($linkedUser->role) === 'admin')) {
                        return response()->json(['message' => 'Admin tidak boleh melakukan checkout'], 403);
                    }
                }
            }
        }
    } catch (\Throwable $_) {
        // ignore check errors and continue (safer to reject earlier), but we keep normal flow
    }
    // =========================================================

    // ======= Tentukan kasir berdasarkan waktu (jika tidak diberikan) =======
    // Gunakan waktu klien bila tersedia: field 'client_time' (ISO8601) atau header 'X-Client-Time'
    $clientTimeRaw = $r->input('client_time') ?? $r->header('X-Client-Time') ?? null;
    $clientTz      = $r->input('client_tz') ?? null;

    if ($clientTimeRaw) {
        try {
            $now = Carbon::parse($clientTimeRaw);
            if ($clientTz) {
                // jika klien kirim timezone, set timezone agar jam sesuai zona klien
                try { $now = $now->setTimezone($clientTz); } catch (\Throwable $e) { /* abaikan */ }
            }
        } catch (\Throwable $e) {
            // parsing gagal -> fallback ke waktu server
            $now = now();
        }
    } else {
        $now = now();
    }

    // Definisi shift:
    // - pagi : 05:00 - 11:59
    // - siang : 12:00 - 17:59
    // - malam : 18:00 - 04:59
    $hour = (int)$now->format('H');
    if ($hour >= 5 && $hour < 12) {
        $shift = 'pagi';
    } elseif ($hour >= 12 && $hour < 18) {
        $shift = 'siang';
    } else {
        $shift = 'malam';
    }

    // Cari kasir pertama dengan shift tersebut (fallback null jika tidak ada)
    $kasirFromTime = DB::table('kasir')->where('shift', $shift)->orderBy('kasir_id')->first();
    $kasirIdTime = $kasirFromTime ? (int)$kasirFromTime->kasir_id : null;

    // Gunakan kasir dari request bila tersedia, jika tidak gunakan yang berdasarkan waktu
    $kasirId = null;
    if (!empty($kasirIdReq)) {
        $kasirId = (int)$kasirIdReq;
    } else {
        $kasirId = $kasirIdTime;
    }
    // =======================================================================

    [$canonId, $_group, $jd] = canonical_jadwal($jadwalId);
    if (!$canonId || !$jd) return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);

    $studioId = (int)$jd->studio_id;
    $filmId   = (int)($jd->film_id ?? 0);

    $filmHarga = null;
    if ($filmId > 0) {
        $hargaVal = DB::table('film')->where('film_id', $filmId)->value('harga');
        if ($hargaVal !== null) {
            $tmp = (int)$hargaVal;
            if ($tmp > 0) $filmHarga = $tmp;
        }
    }
    $defaultPrice = $filmHarga ?? (function(int $studio) {
        $map = [1 => 50000, 2 => 100000, 3 => 75000];
        return $map[$studio] ?? 50000;
    })($studioId);

    $prefix = (function(int $studioId) {
        $base = ord('A') + max(0, $studioId - 1);
        return chr(min($base, ord('Z')));
    })($studioId);

    $ensureKursiId = function(int $id) use ($studioId, $prefix) {
        if ($id >= 0) return $id; // sudah ID asli kursi
        $n       = -$id;
        $sFromId = intdiv($n, 1000);
        $nomor   = $n % 1000;
        if ($sFromId !== $studioId || $nomor <= 0) {
            abort(response()->json(['message' => 'Kursi tidak valid untuk studio ini'], 422));
        }
        $label = $prefix.$nomor;

        $exist = DB::table('kursi')
            ->where('studio_id', $studioId)
            ->where('nomor_kursi', $label)
            ->first();

        if ($exist) return (int)$exist->kursi_id;

        return (int)DB::table('kursi')->insertGetId([
            'nomor_kursi' => $label,
            'studio_id'   => $studioId,
        ]);
    };

    // Tambahkan helper $withTimestamps di sini
    $withTimestamps = function(string $table, array $data) {
        if (Schema::hasColumn($table, 'created_at')) $data['created_at'] = now();
        if (Schema::hasColumn($table, 'updated_at')) $data['updated_at'] = now();
        return $data;
    };

    $paymentMethod = $r->input('payment_method') ?: 'manual_transfer';
    $paymentDestination = $r->input('payment_destination');
    $paymentAccountName = trim((string)$r->input('payment_account_name'));
    if ($paymentAccountName === '') {
        abort(response()->json([
            'message' => 'Nama pemilik rekening wajib diisi dan tidak boleh hanya spasi.',
            'errors' => ['payment_account_name' => ['Nama pemilik rekening wajib diisi.']],
        ], 422));
    }

    return DB::transaction(function () use (
        $ids, $customerForTrans, $canonId, $kasirId, $defaultPrice, $withTimestamps, $ensureKursiId, $filmId,
        $paymentMethod, $paymentDestination, $paymentAccountName
    ) {
        $kursiIds = array_map($ensureKursiId, $ids);

        $tiketRows = [];
        foreach ($kursiIds as $kid) {
            $t = DB::table('tiket')
                ->where('jadwal_id', $canonId)
                ->where('kursi_id',  $kid)
                ->lockForUpdate()
                ->first();

            if (!$t) {
                $ins = [
                    'jadwal_id' => $canonId,
                    'kursi_id'  => $kid,
                    'harga'     => $defaultPrice,
                    'status'    => 'tersedia',
                ];
                $ins   = $withTimestamps('tiket', $ins);
                $tid   = DB::table('tiket')->insertGetId($ins);
                $t     = DB::table('tiket')->where('tiket_id', $tid)->first();
            }

            // Cegah double sell
            $status = strtolower((string)($t->status ?? 'tersedia'));
            if (in_array($status, ['sold','terjual'])) {
                abort(response()->json(['message' => "Kursi $kid sudah terjual"], 409));
            }

            $upd = [];
            $hargaNow = isset($t->harga) ? (int)round((float)$t->harga) : 0;
            if ($hargaNow !== $defaultPrice) {
                $upd['harga'] = $defaultPrice;
            }
            if (!empty($upd)) {
                if (Schema::hasColumn('tiket','updated_at')) $upd['updated_at'] = now();
                DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
                $t->harga = $defaultPrice;
            }

            $tiketRows[] = $t;
        }

        $total = array_sum(array_map(fn($t) => (int)round((float)($t->harga ?? 0)), $tiketRows));

        $trx = [
            'customer_id'       => $customerForTrans,
            'kasir_id'          => $kasirId, // <- tersimpan sesuai waktu/request
            'tanggal_transaksi' => now(),
            'total_harga'       => $total,
            'status'            => 'pending',
            'payment_method'    => $paymentMethod,
            'payment_destination' => $paymentDestination,
            'payment_account_name' => $paymentAccountName,
        ];
        $trx   = $withTimestamps('transaksi', $trx);
        $trxId = DB::table('transaksi')->insertGetId($trx);

        foreach ($tiketRows as $t) {
            $detail = [
                'transaksi_id' => $trxId,
                'tiket_id'     => $t->tiket_id,
                'harga'        => $t->harga,
            ];
            if (Schema::hasColumn('detail_transaksi', 'film_id')) {
                $detail['film_id'] = $filmId;
            }
            $detail = $withTimestamps('detail_transaksi', $detail);
            DB::table('detail_transaksi')->insert($detail);

            $upd = ['status' => 'terjual'];
            if (Schema::hasColumn('tiket','updated_at')) $upd['updated_at'] = now();
            DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
        }

        // Label kursi
        $kursiIdList = array_map(fn($t) => (int)$t->kursi_id, $tiketRows);
        $kursiMap = DB::table('kursi')
            ->whereIn('kursi_id', $kursiIdList)
            ->pluck('nomor_kursi', 'kursi_id');

        $kursiDetail = array_map(function($t) use ($kursiMap) {
            $kid = (int)$t->kursi_id;
            return [
                'tiket_id'    => (int)$t->tiket_id,
                'kursi_id'    => $kid,
                'nomor_kursi' => (string)($kursiMap[$kid] ?? ('S'.$kid)),
                'harga'       => (int)round((float)($t->harga ?? 0)),
            ];
        }, $tiketRows);

        return response()->json([
            'ok'            => true,
            'transaksi_id'  => $trxId,
            'total_harga'   => (int)$total,
            'kursi_terbeli' => $kursiIdList,
            'kursi'         => $kursiDetail,
            'kursi_labels'  => implode(', ', array_map(fn($d)=>$d['nomor_kursi'], $kursiDetail)),
            'status'        => 'pending',
            'payment_method' => $paymentMethod,
            'payment_destination' => $paymentDestination,
        ]);
    });
});
Route::match(['patch', 'post'], '/transaksi/{id}/status', [TransaksiController::class, 'setStatus'])
    ->whereNumber('id');
Route::apiResource('users', UserController::class);
/* ---------- ekstensi opsional ---------- */
$append = __DIR__ . '/api.append.php';
if (file_exists($append)) { require $append; }