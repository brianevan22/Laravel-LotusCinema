
# Bioskop API Patch (Laravel)

Generated: 2025-10-30T10:21:05.756381Z

This patch adds Eloquent models, API controllers, and routes for your existing database schema (`bioskop.sql`).

## What it includes
- `app/Models/*.php` for each table
- `app/Http/Controllers/Api/*Controller.php` (CRUD)
- `routes/api.append.php` with API routes to append to your `routes/api.php`
- `app/Http/Resources/*Resource.php` (for cleaner JSON)
- Middleware-free, stateless API (works with Flutter). For authentication, enable Laravel Sanctum (instructions below).

## Install steps

1. **Unzip** this archive into your Laravel project root, letting it place files into `app/Models`, `app/Http/Controllers/Api`, `app/Http/Resources`, and create a `routes/api.append.php` file.
2. **Append routes**: open `routes/api.php` and paste the contents of `routes/api.append.php` to the bottom of the file (or include it with `require __DIR__.'/api.append.php';`).
3. Ensure your `.env` DB config points to your imported database.
4. Clear caches:
   ```bash
   php artisan optimize:clear
   php artisan route:clear
   ```
5. (Optional but recommended) **Auth via Sanctum**:
   ```bash
   composer require laravel/sanctum
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate
   ```
   Then protect routes by wrapping them in `Route::middleware('auth:sanctum')->group(function() Ellipsis);`
6. Test endpoints (examples):
   - GET `/api/films?per_page=10&search=action`
   - POST `/api/films` JSON: `{"judul":"...", "durasi":120, "sinopsis":"...", "genre_id":1}`
   - PUT `/api/films/1` JSON: `{"judul":"Baru"}`
   - DELETE `/api/films/1`

## Flutter quick connect

Use `dio` or `http`:
```dart
final res = await dio.get('http://127.0.0.1:8000/api/films');
print(res.data);
```

If using **Sanctum** token auth, include header:
```dart
dio.options.headers['Authorization'] = 'Bearer YOUR_TOKEN';
```

