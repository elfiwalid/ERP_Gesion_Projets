<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemandeDocument extends Model
{
    protected $table = 'demande_documents';

    protected $fillable = [
        'demande_id',
        'nom',
        'is_brief',
        'statut',        // A_IMPORTER | EN_COURS | VALIDE | REFUSE
        'motif_refus',   // <--- nouveau
        'fichier_path',
        'uploaded_by',
    ];

    protected $casts = [
        'is_brief' => 'boolean',
    ];

    // Ajout d'un champ virtuel "assigned_to" (non stocké)
    protected $appends = ['assigned_to'];

    public function getAssignedToAttribute(): string
    {
        // Règle d'assignation implicite
        return $this->is_brief ? 'CES' : 'RA';
    }

    public function demande()  { return $this->belongsTo(Demande::class); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
