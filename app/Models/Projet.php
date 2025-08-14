<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Projet extends Model
{
    protected $table = 'projets';

    protected $fillable = [
        'client_id',
        'demande_id',
        'nom',
        'date_debut',
        'date_fin_prevue',
        'statut',      // BROUILLON | EN_VALIDATION | REFUSE | CLOTURE | ARCHIVE | PRET_DESIGN
        'archived_at',
        'cree_par',
    ];

    protected $casts = [
        'date_debut'      => 'date',
        'date_fin_prevue' => 'date',
        'archived_at'     => 'datetime',
    ];

    public function client()  { return $this->belongsTo(Client::class); }
    public function demande() { return $this->belongsTo(Demande::class); }
    public function createur(){ return $this->belongsTo(User::class, 'cree_par'); }
    public function pieces()  { return $this->hasMany(Piece::class); }
}
