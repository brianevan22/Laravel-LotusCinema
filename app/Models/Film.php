<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Film extends Model
{
    use HasFactory;

    protected $table = 'film';
    protected $primaryKey = 'film_id';
    public $timestamps = false;

    protected $fillable = [
        'judul',
        'genre_id',
        'durasi_menit',
        'rating_umur',
        'deskripsi',
        'poster_url',
        'harga_default',
        'created_at'
    ];

    public function genre()
    {
        return $this->belongsTo(Genre::class, 'genre_id', 'genre_id');
    }
}
