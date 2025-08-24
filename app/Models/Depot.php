<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Depot extends Model
{
    protected $table = 'depots';

    protected $fillable = [
        'projet_id',
        'mode',
        'status',
        'meta_email',
        'meta_physical',
        'meta_platform',
        'physique_file_path',
        'physique_file_name',
        'created_by',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'meta_email'    => 'array',
        'meta_physical' => 'array',
        'meta_platform' => 'array',
        'sent_at'       => 'datetime',
    ];

    public function projet()
    {
        return $this->belongsTo(\App\Models\Projet::class, 'projet_id');
    }
}
