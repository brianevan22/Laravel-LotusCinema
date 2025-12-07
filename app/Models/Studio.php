<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Studio extends Model
{
    use HasFactory;

    protected $table = 'studio';
    protected $primaryKey = 'studio_id';
    public $timestamps = false;

    protected $fillable = [
        'nama_studio',
        'tipe_studio',
        'kapasitas'
    ];

    public function kursi()
    {
        return $this->hasMany(Kursi::class, 'studio_id', 'studio_id');
    }
}
