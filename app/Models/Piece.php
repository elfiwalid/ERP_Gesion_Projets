<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Piece extends Model
{
    protected $table = 'pieces';

    protected $fillable = [
        'projet_id',
        'nom',
        'description',
        'obligatoire',
        'statut',        // A_IMPORTER | EN_COURS | VALIDE | REFUSE
        'fichier_path',
        'uploaded_by',
        'assigned_user_id',
        'assigned_by',
        'due_date',
    ];

    protected $casts = [
        'obligatoire' => 'boolean',
        'due_date'    => 'date',
    ];

    public function projet()      { return $this->belongsTo(Projet::class); }
    public function uploader()    { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function assignee()    { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function assignateur() { return $this->belongsTo(User::class, 'assigned_by'); }
}
