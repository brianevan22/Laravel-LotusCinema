<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kasir extends Model
{
    use HasFactory;

    protected $table = 'kasir';
    protected $primaryKey = 'kasir_id';
    public $timestamps = false;

    protected $fillable = [
        'nama',
        'shift',
        'no_hp'
    ];

    public function transaksi()
    {
        return $this->hasMany(Transaksi::class, 'kasir_id', 'kasir_id');
    }
}
