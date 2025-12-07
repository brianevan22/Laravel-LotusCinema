<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jadwal extends Model
{
    use HasFactory;

    protected $table = 'jadwal';
    protected $primaryKey = 'jadwal_id';
    public $timestamps = false;

    protected $fillable = [
        'film_id',
        'studio_id',
        'tanggal',
        'jam_mulai',
        'jam_selesai'
    ];

    public function film()
    {
        return $this->belongsTo(Film::class, 'film_id', 'film_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class, 'studio_id', 'studio_id');
    }

    public function tiket()
    {
        return $this->hasMany(Tiket::class, 'jadwal_id', 'jadwal_id');
    }
}
