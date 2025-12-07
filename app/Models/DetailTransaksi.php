<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailTransaksi extends Model
{
    use HasFactory;

    protected $table = 'detail_transaksi';
    protected $primaryKey = 'detail_id';
    public $timestamps = false;

    protected $fillable = [
        'transaksi_id',
        'tiket_id',
        'harga'
    ];

    public function transaksi()
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_id', 'transaksi_id');
    }

    public function tiket()
    {
        return $this->belongsTo(Tiket::class, 'tiket_id', 'tiket_id');
    }
}
