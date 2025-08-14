<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $table = 'clients';

    protected $fillable = [
        'raison_sociale',
        'contact_nom',
        'contact_email',
        'contact_telephone',
        'adresse',
        'metadonnees',
    ];

    protected $casts = [
        'metadonnees' => 'array',
    ];

    public function projets()  { return $this->hasMany(Projet::class); }
    public function demandes() { return $this->hasMany(Demande::class); }
}
