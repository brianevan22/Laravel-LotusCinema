<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JadwalController extends Controller
{
    private const MIN_SEATS_PER_STUDIO = 15; // minimal 15 kursi / studio
    private const DESIRED_LAYOUT = [
        'A' => 8,
        'B' => 8,
        'C' => 8,
        'D' => 8,
        'E' => 10,
    ];

    private function hasCol(string $table, string $col): bool
    {
        try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
    }

    private function studioPrefix(int $studioId): string
    {
        // 1->A, 2->B, 3->C, ...
        $ord = ord('A') + max(0, $studioId - 1);
        $ord = min($ord, ord('Z'));
        return chr($ord);
    }

    private function defaultPrice(int $studioId): int
    {
        // Ubah mapping jika perlu
        $map = [1 => 50000, 2 => 100000, 3 => 75000];
        return $map[$studioId] ?? 50000;
    }

    private function filmPrice(?int $filmId): ?int
    {
        if (!$filmId) return null;
        $harga = DB::table('film')->where('film_id', $filmId)->value('harga');
        if ($harga === null) return null;
        $value = (int)$harga;
        return $value > 0 ? $value : null;
    }

    /** Cari grup slot dan jadwal kanonik (id terkecil) untuk sebuah jadwal */
    private function canonicalFor(int $jadwalId): array
    {
        $jd = DB::table('jadwal')->where('jadwal_id', $jadwalId)->first();
        // Ganti abort dengan return array kosong/null agar bisa dihandle manual jika perlu,
        // tapi untuk saat ini biarkan abort jika dipanggil oleh getSeats (yang biasanya aman).
        // Kita TIDAK akan memanggil fungsi ini lagi di destroy() untuk mencegah error 302.
        if (!$jd) abort(response()->json(['message'=>'Jadwal tidak ditemukan'], 404));

        // Definisi SLOT: studio_id + tanggal + jam_mulai
        $ids = DB::table('jadwal')
            ->where('studio_id', $jd->studio_id)
            ->where('tanggal',   $jd->tanggal)
            ->where('jam_mulai', $jd->jam_mulai)
            ->orderBy('jadwal_id')
            ->pluck('jadwal_id')
            ->all();

        if (empty($ids)) abort(response()->json(['message'=>'Jadwal tidak ditemukan'], 404));

        $canonId = (int)min($ids);
        return [$canonId, $ids, $jd];
    }

    private function desiredSeatList(): array
    {
        $list = [];
        foreach (self::DESIRED_LAYOUT as $row => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $list[] = $row . $i;
            }
        }
        return $list;
    }

    /**
     * Pastikan minimal 15 kursi tercatat pada studio:
     * A1..A15 (studio 1), B1..B15 (studio 2), dst.
     */
    private function ensureStudioSeats(int $studioId): array
    {
        $desiredLabels = $this->desiredSeatList();
        $existing = DB::table('kursi')
            ->where('studio_id', $studioId)
            ->get()
            ->keyBy('nomor_kursi');

        $result = [];
        foreach ($desiredLabels as $label) {
            if (isset($existing[$label])) {
                $result[$label] = (int) $existing[$label]->kursi_id;
            } else {
                $result[$label] = DB::table('kursi')->insertGetId([
                    'nomor_kursi' => $label,
                    'studio_id'   => $studioId,
                ]);
            }
        }

        return $result;
    }

    // ================== API ==================

    // GET /api/jadwal?film_id=2
    public function index(Request $r)
    {
        $q = DB::table('jadwal as j')
            ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
            ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
            ->select(
                'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
                DB::raw('f.judul as film_judul'),
                DB::raw('s.nama_studio as nama_studio')
            );

        if ($r->filled('film_id')) {
            $q->where('j.film_id', (int)$r->input('film_id'));
        }

        $rows = $q->orderBy('j.tanggal')->orderBy('j.jam_mulai')->get();

        return response()->json($rows);
    }

    // POST /api/jadwal
    public function store(Request $r)
    {
        $r->validate([
            'film_id'     => 'required|integer',
            'studio_id'   => 'required|integer',
            'tanggal'     => 'required|date',
            'jam_mulai'   => 'required|date_format:H:i:s',
            'jam_selesai' => 'required|date_format:H:i:s',
        ]);

        $data = $r->only(['film_id','studio_id','tanggal','jam_mulai','jam_selesai']);
        if ($this->hasCol('jadwal','created_at')) $data['created_at'] = now();
        if ($this->hasCol('jadwal','updated_at')) $data['updated_at'] = now();

        $id = DB::table('jadwal')->insertGetId($data);
        $row = DB::table('jadwal')->where('jadwal_id',$id)->first();

        return response()->json($row, 201);
    }

    // GET /api/jadwal/by-film/{film}
    public function getByFilm($filmId)
    {
        $rows = DB::table('jadwal as j')
            ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
            ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
            ->where('j.film_id', (int)$filmId)
            ->orderBy('j.tanggal')
            ->orderBy('j.jam_mulai')
            ->get([
                'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
                DB::raw('f.judul as film_judul'),
                DB::raw('s.nama_studio as nama_studio')
            ]);

        return response()->json($rows);
    }

    /**
     * GET /api/jadwal/{jadwal}/seats
     */
    public function getSeats($jadwalId, Request $r)
    {
        [$canonId, $_group, $jd] = $this->canonicalFor((int) $jadwalId);

        $studioId     = (int) $jd->studio_id;
        $filmHarga    = $this->filmPrice((int) ($jd->film_id ?? null));
        $hargaDefault = $filmHarga ?? $this->defaultPrice($studioId);

        return DB::transaction(function () use ($canonId, $studioId, $hargaDefault) {
            $desiredSeats = $this->ensureStudioSeats($studioId);
            $seatIds = array_values($desiredSeats);
            $labelOrder = array_keys($desiredSeats);
            $orderSql = 'FIELD(k.nomor_kursi, ' .
                implode(',', array_map(fn ($label) => "'" . $label . "'", $labelOrder)) .
                ')';

            foreach ($desiredSeats as $label => $seatId) {
                $t = DB::table('tiket')
                    ->where('jadwal_id', $canonId)
                    ->where('kursi_id', $seatId)
                    ->lockForUpdate()
                    ->first();

                if (!$t) {
                    $ins = [
                        'jadwal_id' => $canonId,
                        'kursi_id'  => $seatId,
                        'harga'     => $hargaDefault,
                        'status'    => 'tersedia',
                    ];
                    $ins = $this->hasCol('tiket', 'created_at') ? $this->withTimestamps('tiket', $ins) : $ins;
                    $tid = DB::table('tiket')->insertGetId($ins);
                    $t = DB::table('tiket')->where('tiket_id', $tid)->first();
                } else {
                    $status = strtolower((string) ($t->status ?? 'tersedia'));
                    $status = in_array($status, ['sold', 'terjual']) ? 'terjual' : 'tersedia';

                    $upd = [];
                    if ($status !== $t->status) {
                        $upd['status'] = $status;
                    }
                    $hargaNow = isset($t->harga) ? (int) round((float) $t->harga) : 0;
                    if ($hargaNow !== $hargaDefault) {
                        $upd['harga'] = $hargaDefault;
                    }
                    if (!empty($upd)) {
                        if ($this->hasCol('tiket', 'updated_at')) {
                            $upd['updated_at'] = now();
                        }
                        DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
                        $t->harga = $upd['harga'] ?? $t->harga;
                    }
                }
            }

            $rows = DB::table('kursi as k')
                ->leftJoin('tiket as t', function ($j) use ($canonId) {
                    $j->on('t.kursi_id', '=', 'k.kursi_id')
                      ->where('t.jadwal_id', '=', $canonId);
                })
                ->where('k.studio_id', $studioId)
                ->whereIn('k.kursi_id', $seatIds)
                ->orderByRaw($orderSql)
                ->get([
                    'k.kursi_id',
                    DB::raw('COALESCE(k.nomor_kursi, CONCAT("S", k.kursi_id)) as nama_kursi'),
                    DB::raw('(CASE WHEN t.harga IS NULL OR t.harga <= 0 THEN ' . $hargaDefault . ' ELSE t.harga END) + 0 as harga'),
                    DB::raw('CASE LOWER(COALESCE(t.status,"tersedia"))
                                WHEN "sold" THEN "terjual"
                                WHEN "terjual" THEN "terjual"
                                ELSE "tersedia"
                             END as status'),
                    DB::raw('t.tiket_id as tiket_id'),
                ]);

            $rows = $rows->map(function ($r) {
                $amount = (int) round((float) ($r->harga ?? 0));
                $r->harga = $amount;
                $r->price = $amount;
                $r->harga_int = $amount;
                $r->status = strtolower($r->status ?? 'tersedia') === 'terjual' ? 'terjual' : 'tersedia';
                return $r;
            });

            return response()->json($rows->values());
        });
    }

    // Tambahkan fungsi ini di dalam class
    private function userFromToken(): ?object
    {
        $req = request();
        $token = $req?->bearerToken();
        if (!$token) {
            $token = $req?->input('token') ?? $req?->query('token');
        }
        if (!$token) {
            $auth = $req?->header('Authorization') ?? $req?->header('authorization') ?? '';
            if (!empty($auth)) {
                if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = trim($m[1]);
                else $token = trim($auth);
            }
        }
        if (!$token || !\Schema::hasTable('users')) return null;
        $pk = \Schema::hasColumn('users','id_users') ? 'id_users' : (\Schema::hasColumn('users','id') ? 'id' : 'id');
        if (\Schema::hasColumn('users','api_token')) {
            $u = \DB::table('users')->where('api_token', $token)->first();
            if ($u) return $u;
        }
        if (is_numeric($token)) {
            $val = strpos($token, '.') !== false ? (int)floor(floatval($token)) : (int)$token;
            $u = \DB::table('users')->where($pk, $val)->first();
            if ($u) return $u;
        }
        $u = \DB::table('users')->where('username', $token)->orWhere('name', $token)->first();
        if ($u) return $u;
        return null;
    }

    // Tambahkan pengecekan admin pada update
    public function update(Request $r, $id)
    {
        $user = $this->userFromToken();
        if (!$user || (isset($user->role) && strtolower($user->role) !== 'admin')) {
            return response()->json(['message' => 'Hanya admin yang dapat mengedit jadwal'], 403);
        }
        $r->validate([
            'film_id'     => 'required|integer',
            'studio_id'   => 'required|integer',
            'tanggal'     => 'required|date',
            'jam_mulai'   => 'required|date_format:H:i:s',
            'jam_selesai' => 'required|date_format:H:i:s',
        ]);

        $data = $r->only(['film_id','studio_id','tanggal','jam_mulai','jam_selesai']);
        if ($this->hasCol('jadwal','updated_at')) $data['updated_at'] = now();

        DB::table('jadwal')->where('jadwal_id', $id)->update($data);
        $row = DB::table('jadwal')->where('jadwal_id', $id)->first();

        return response()->json($row);
    }

    // Tambahkan pengecekan admin pada destroy
    public function destroy($id)
    {
        $user = $this->userFromToken();
        if (!$user || (isset($user->role) && strtolower($user->role) !== 'admin')) {
            return response()->json(['message' => 'Hanya admin yang dapat menghapus jadwal'], 403);
        }
        // Gunakan try-catch untuk menangkap semua error dan memastikan balasan JSON
        try {
            $id = (int)$id;
            
            // Cek jadwal ada atau tidak
            $jadwal = DB::table('jadwal')->where('jadwal_id', $id)->first();

            if (!$jadwal) {
                // Return JSON langsung, jangan pakai abort() untuk mencegah 302/404 page html
                return response()->json(['message' => 'Jadwal tidak ditemukan'], 404);
            }

            // Hapus tiket yang berelasi dengan jadwal ini terlebih dahulu
            // agar tidak terkena error foreign key constraint
            DB::table('tiket')->where('jadwal_id', $id)->delete();
            
            // Hapus jadwal
            DB::table('jadwal')->where('jadwal_id', $id)->delete();

            return response()->json([
                'status' => true,
                'deleted' => true,
                'message' => 'Jadwal berhasil dihapus'
            ], 200);

        } catch (\Throwable $e) {
            // Jika ada error database atau lainnya, kembalikan JSON 500
            return response()->json([
                'status' => false,
                'deleted' => false,
                'message' => 'Gagal menghapus jadwal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}