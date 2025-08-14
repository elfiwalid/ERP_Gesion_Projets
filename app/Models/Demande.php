<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Demande extends Model
{
    protected $table = 'demandes';

    protected $fillable = [
        'client_id',
        'type',        // BRIEF | APPEL_OFFRE
        'intitule',
        'description',
        'statut',      // BROUILLON | EN_COURS | TERMINEE
        'cree_par',
    ];

    public function client()   { return $this->belongsTo(Client::class); }
    public function documents(){ return $this->hasMany(DemandeDocument::class); }
    public function createur() { return $this->belongsTo(User::class, 'cree_par'); }
    public function projet()   { return $this->hasOne(Projet::class); }
}
