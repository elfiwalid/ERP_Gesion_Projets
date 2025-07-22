<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'projet_id',
        'name',
        'type',
        'path',
        'status',
        'shared_with',
        'uploaded_by',
        'validated_by',
    ];

    public function projet()
    {
        return $this->belongsTo(Projet::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function validatedBy()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
