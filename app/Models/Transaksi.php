<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaksi extends Model
{
    use HasFactory;

    protected $table = 'transaksi';
    protected $primaryKey = 'transaksi_id';
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'kasir_id',
        'tanggal_transaksi',
        'total_harga',
        'status',
        'payment_method',
        'payment_destination',
        'payment_account_name',
        'paid_at',
    ];

    protected $casts = [
        'tanggal_transaksi' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function detail()
    {
        return $this->hasMany(DetailTransaksi::class, 'transaksi_id', 'transaksi_id');
    }
}
