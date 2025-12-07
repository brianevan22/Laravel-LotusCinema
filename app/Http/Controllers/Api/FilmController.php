<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\File;

class FilmController extends Controller
{
    private function hasCol(string $table, string $col): bool
    {
        try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
    }

    private function withTimestamps(string $table, array $data): array
    {
        if ($this->hasCol($table, 'created_at')) $data['created_at'] = now();
        if ($this->hasCol($table, 'updated_at')) $data['updated_at'] = now();
        return $data;
    }

    // --- HELPER URL ---
    private function baseHost(): string
    {
        if ($request = request()) {
            $host = $request->getSchemeAndHttpHost();
            if (!empty($host)) {
                return rtrim($host, '/');
            }
        }
        return rtrim(config('app.url') ?? URL::to('/'), '/');
    }

    private function normalizedPosterFilename(?string $value): ?string
    {
        if ($value === null) return null;
        $clean = preg_replace('#^(assets/|poster/|public/poster/)+#i', '', ltrim(trim($value)));
        return $clean === '' ? null : $clean;
    }

    private function posterRelativePath(string $filename): string
    {
        $clean = ltrim($filename, '/');
        return Str::startsWith($clean, 'poster/') ? $clean : 'poster/' . $clean;
    }

    private function absolutePosterPath(?string $path): ?string
    {
        if (!$path) return null;
        if (preg_match('#^https?://#i', $path)) return $path;
        
        $trimmed = ltrim($path, '/');
        return $this->baseHost() . '/' . $trimmed;
    }

    private function decoratePosterResponse(array $data): array
    {
        $posterFilename = $this->normalizedPosterFilename($data['poster'] ?? null);
        
        if ($posterFilename) {
            $relative = $this->posterRelativePath($posterFilename);
            $data['poster'] = $posterFilename;
            $data['poster_path'] = $relative;
            $data['poster_asset_url'] = $this->absolutePosterPath($relative);
        } elseif (!empty($data['poster_asset_url'])) {
            $data['poster_asset_url'] = $this->absolutePosterPath($data['poster_asset_url']);
        } elseif (!empty($data['poster_url'])) {
            $data['poster_asset_url'] = $this->absolutePosterPath($data['poster_url']);
        }

        return $data;
    }

    // --- API METHODS ---

    public function index(Request $r)
    {
        $flat = $r->boolean('flat', false);

        $q = DB::table('film as f')
            ->leftJoin('genre as g', 'g.genre_id', '=', 'f.genre_id');
            
        $select = [
            'f.film_id','f.judul','f.durasi','f.sinopsis','f.genre_id','f.harga',
            DB::raw('g.nama_genre as genre_name'),
        ];
        
        foreach (['poster', 'poster_url', 'poster_base64', 'poster_filename', 'poster_asset_url'] as $column) {
            if ($this->hasCol('film', $column)) {
                $select[] = "f.$column";
            }
        }
        $q->select($select);

        if ($r->filled('search')) {
            $s = '%'.$r->query('search').'%';
            $q->where(function($w) use ($s) {
                $w->where('f.judul','like',$s)
                  ->orWhere('f.sinopsis','like',$s);
            });
        }

        $builder = $q->orderBy('f.film_id','desc');

        if ($r->filled('page') || $r->filled('per_page')) {
            $per = max(1, (int)$r->query('per_page', 10));
            $data = $builder->paginate($per);
            $data->getCollection()->transform(fn ($row) => $this->decoratePosterResponse((array)$row));
            return response()->json($data);
        }

        $rows = $builder->get()->map(fn ($row) => $this->decoratePosterResponse((array)$row));

        if ($flat) {
            return response()->json([
                'ok'    => true,
                'count' => $rows->count(),
                'data'  => $rows->values(),
            ]);
        }

        return response()->json($rows->values());
    }

    public function show($id)
    {
        $select = [
            'f.film_id','f.judul','f.durasi','f.sinopsis','f.genre_id','f.harga',
            DB::raw('g.nama_genre as genre_name'),
        ];
        foreach (['poster', 'poster_url', 'poster_base64', 'poster_filename', 'poster_asset_url'] as $column) {
            if ($this->hasCol('film', $column)) {
                $select[] = "f.$column";
            }
        }
        
        $row = DB::table('film as f')
            ->leftJoin('genre as g', 'g.genre_id', '=', 'f.genre_id')
            ->where('f.film_id', (int)$id)
            ->first($select);

        if (!$row) return response()->json(['message'=>'Film tidak ditemukan'], 404);
        return response()->json($this->decoratePosterResponse((array)$row));
    }

    public function store(Request $r)
    {
        // Increase memory limit for image processing temporarily
        ini_set('memory_limit', '256M');

        $r->validate([
            'judul'    => ['required', 'string', 'max:255', Rule::unique('film', 'judul')],
            'durasi'   => 'nullable|integer|min:1',
            'sinopsis' => 'nullable|string',
            'genre_id' => 'nullable|integer|exists:genre,genre_id',
            'harga'    => 'nullable|integer|min:0',
            'poster_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120', // Max 5MB
        ]);

        $data = $r->only(['judul','durasi','sinopsis','genre_id']);
        $data['harga'] = (int) $r->input('harga', 0);
        
        $posterPayload = $this->normalisePoster($r);
        $data = array_merge($data, $posterPayload);
        
        // UPLOAD FILE KE PUBLIC/POSTER
        if ($r->hasFile('poster_file')) {
            $file = $r->file('poster_file');
            $filename = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            
            $file->move(public_path('poster'), $filename);
            
            $data['poster'] = $filename;
            if ($this->hasCol('film', 'poster_asset_url')) {
                $data['poster_asset_url'] = $filename;
            }
        }

        $data = $this->withTimestamps('film', $data);
        $id = DB::table('film')->insertGetId($data);
        
        return $this->show($id);
    }

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

    public function update(Request $r, $id)
    {
        // --- Tambahkan pengecekan admin ---
        $user = $this->userFromToken();
        if (!$user || (isset($user->role) && strtolower($user->role) !== 'admin')) {
            return response()->json(['message' => 'Hanya admin yang dapat mengedit film'], 403);
        }
        ini_set('memory_limit', '256M');
        
        $row = DB::table('film')->where('film_id', (int)$id)->first();
        if (!$row) return response()->json(['message'=>'Film tidak ditemukan'], 404);

        $r->validate([
            'judul'     => [
                'sometimes', 'required', 'string', 'max:255', 
                Rule::unique('film', 'judul')->ignore($id, 'film_id')
            ],
            'durasi'    => 'sometimes|nullable|integer|min:1',
            'sinopsis'  => 'sometimes|nullable|string',
            'genre_id'  => 'sometimes|nullable|integer|exists:genre,genre_id',
        ]);

        $upd = $r->only(['judul','durasi','sinopsis','genre_id']);
        if ($r->has('harga')) {
            $upd['harga'] = (int) $r->input('harga', 0);
        }

        $posterPayload = $this->normalisePoster($r);
        if (!empty($posterPayload)) {
            $upd = array_merge($upd, $posterPayload);
        }

        // UPLOAD FILE UPDATE
        if ($r->hasFile('poster_file')) {
            // Hapus file lama
            $oldPoster = $row->poster ?? ($row->poster_asset_url ?? null);
            if ($oldPoster && File::exists(public_path('poster/' . $oldPoster))) {
                File::delete(public_path('poster/' . $oldPoster));
            }

            $file = $r->file('poster_file');
            $filename = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            
            $file->move(public_path('poster'), $filename);
            
            $upd['poster'] = $filename;
            if ($this->hasCol('film', 'poster_asset_url')) {
                $upd['poster_asset_url'] = $filename;
            }
        }

        if ($this->hasCol('film','updated_at')) $upd['updated_at'] = now();

        DB::table('film')->where('film_id', (int)$id)->update($upd);
        return $this->show($id);
    }

    public function destroy($id)
    {
        // --- Tambahkan pengecekan admin ---
        $user = $this->userFromToken();
        if (!$user || (isset($user->role) && strtolower($user->role) !== 'admin')) {
            return response()->json(['message' => 'Hanya admin yang dapat menghapus film'], 403);
        }
        try {
            $film = DB::table('film')->where('film_id', (int)$id)->first();
            if (!$film) return response()->json(['message'=>'Film tidak ditemukan'], 404);

            $poster = $film->poster ?? ($film->poster_asset_url ?? null);
            if ($poster) {
                $posterPath = public_path('poster/' . $poster);
                if (File::exists($posterPath)) {
                    File::delete($posterPath);
                }
            }

            // Hapus tiket/jadwal terkait jika perlu (optional, tergantung struktur DB)
            DB::table('jadwal')->where('film_id', $id)->delete();

            DB::table('film')->where('film_id', (int)$id)->delete();
            return response()->json(['deleted' => true, 'message' => 'Film berhasil dihapus']);
        } catch (\Throwable $e) {
            return response()->json([
                'deleted' => false,
                'message' => 'Gagal menghapus: film mungkin berelasi dengan data lain',
                'error'   => $e->getMessage(),
            ], 409);
        }
    }

    private function normalisePoster(Request $request): array
    {
        $payload = [];
        $base64 = trim((string) $request->input('poster_base64', ''));
        $existing = $this->normalizedPosterFilename($request->input('poster'));

        if ($base64 !== '') {
            $saved = $this->savePosterToAssets($base64, $request->input('poster_filename'));
            if ($saved) {
                $payload['poster'] = $saved['filename'];
                if ($this->hasCol('film', 'poster_filename')) {
                    $payload['poster_filename'] = $saved['filename'];
                }
            }
            if ($this->hasCol('film', 'poster_base64')) {
                $payload['poster_base64'] = $base64;
            }
        } elseif ($existing) {
            $payload['poster'] = $existing;
        }
        return $payload;
    }

    private function savePosterToAssets(string $raw, ?string $original = null): ?array
    {
        $data = $raw;
        if (str_contains($data, ',')) {
            [, $data] = explode(',', $data, 2);
        }
        $binary = base64_decode($data, true);
        if ($binary === false) return null;

        $baseName = $original ? pathinfo($original, PATHINFO_FILENAME) : 'poster';
        $cleanBase = Str::slug($baseName) ?: 'poster';
        $fileName = $cleanBase . '.png';

        $publicDir = public_path('poster');
        if (!is_dir($publicDir)) mkdir($publicDir, 0775, true);

        $absolute = $publicDir . DIRECTORY_SEPARATOR . $fileName;
        $counter = 2;
        while (file_exists($absolute)) {
            $fileName = $cleanBase . '-' . $counter . '.png';
            $absolute = $publicDir . DIRECTORY_SEPARATOR . $fileName;
            $counter++;
        }

        $converted = false;
        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $img = @imagecreatefromstring($binary);
            if ($img !== false) {
                // Kompresi level 4 (menengah) agar upload cepat
                imagepng($img, $absolute, 4); 
                imagedestroy($img);
                $converted = true;
            }
        }
        if (!$converted) {
            file_put_contents($absolute, $binary);
        }

        return ['filename' => $fileName];
    }
}