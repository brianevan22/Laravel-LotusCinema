<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tiket extends Model
{
    use HasFactory;

    protected $table = 'tiket';
    protected $primaryKey = 'tiket_id';
    public $timestamps = false;

    protected $fillable = [
        'jadwal_id',
        'kursi_id',
        'harga',
        'status'
    ];

    public function jadwal()
    {
        return $this->belongsTo(Jadwal::class, 'jadwal_id', 'jadwal_id');
    }

    public function kursi()
    {
        return $this->belongsTo(Kursi::class, 'kursi_id', 'kursi_id');
    }
}
