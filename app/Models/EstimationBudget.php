<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimationBudget extends Model
{
    protected $fillable = [
        'projet_id', 'label', 'amount', 'position', 'created_by', 'updated_by'
    ];

    public function projet() { return $this->belongsTo(Projet::class); }
}
