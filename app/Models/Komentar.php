<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Komentar extends Model
{
    use HasFactory;

    protected $table = 'komentar';
    protected $primaryKey = 'komentar_id';
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'film_id',
        'isi_komentar',
        'rating'
    ];

    public function film()
    {
        return $this->belongsTo(Film::class, 'film_id', 'film_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
