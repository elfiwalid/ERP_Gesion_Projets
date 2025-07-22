<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Projet extends Model
{
    protected $table = 'projets';
    protected $fillable = [
        'name',
        'client_name',
        'status',
        'created_by',
        'validated_by',
    ];

    /**
     * Le créateur du projet (Responsable Administratif)
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * La personne qui a validé le projet (Admin Général ou Super Chef Terrain)
     */
    public function validatedBy()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * Les documents liés au projet
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
