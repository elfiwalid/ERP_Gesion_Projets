<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectApproval extends Model
{
    protected $table = 'project_approvals';

    protected $fillable = [
        'projet_id',
        'role_id',      // ex: 1=AdminG, 3=Chef Terrain Sup, 5=Chargé d'études Sup
        'decision',     // PENDING | APPROUVE | REFUSE
        'motif',
        'decided_by',   // user_id
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
