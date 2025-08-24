<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Soutenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'depot_id','date_time','lieu','meeting_url','statut','notes',
        'invites','feedbacks','created_by','updated_by'
    ];

    protected $casts = [
        'date_time' => 'datetime',
        'invites'   => 'array',
        'feedbacks' => 'array',
    ];

    public function depot()
    {
        return $this->belongsTo(Depot::class);
    }

    // 🎁 Accesseurs pratiques (pas de relation Eloquent, juste lecture)
    public function getProjetAttribute()
    {
        return $this->depot ? $this->depot->projet : null;
    }

    public function getProjetIdAttribute()
    {
        return $this->depot ? $this->depot->projet_id : null;
    }
}
