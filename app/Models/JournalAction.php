<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalAction extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'module',
        'details',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
