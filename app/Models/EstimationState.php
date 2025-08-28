<?php

// app/Models/EstimationState.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimationState extends Model
{
    protected $fillable = [
        'projet_id','statut','motif_refus',
        'uploaded_by','uploaded_by_role_id','uploaded_at',
        'chef_approved_by','chef_approved_at',
        'admin_approved_by','admin_approved_at',
    ];

    protected $casts = [
        'uploaded_at'      => 'datetime',
        'chef_approved_at' => 'datetime',
        'admin_approved_at'=> 'datetime',
    ];
}
