<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Codigo extends Model
{
    use HasFactory;

    protected $table = 'codigos';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'prefijo',
        'carrera',
        'indice',
    ];

    protected function casts(): array
    {
        return [
            'indice' => 'integer',
        ];
    }
}
