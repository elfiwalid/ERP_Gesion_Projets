<?php

// app/Models/EstimationAudit.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimationAudit extends Model
{
    protected $fillable = ['projet_id','actor_id','actor_role_id','action','old_json','new_json','motif'];

    protected $casts = [
        'old_json' => 'array',
        'new_json' => 'array',
    ];
}
