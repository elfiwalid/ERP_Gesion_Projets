<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFinance extends Model
{
    // C'est offre Financier pour chaque projet 
    protected $table = 'project_finances';

    protected $fillable = [
        'projet_id',
        'estimation_statut',
        'estimation_file_path',
        'estimation_uploaded_by',
        'estimation_uploaded_by_role_id',
        'estimation_uploaded_at',
        'chef_approved_by',
        'chef_approved_at',
        'admin_approved_by',
        'admin_approved_at',
        'estimation_motif_refus',
    ];

    protected $casts = [
        'estimation_uploaded_at' => 'datetime',
        'chef_approved_at'       => 'datetime',
        'admin_approved_at'      => 'datetime',
    ];

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id');
    }
}
