<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialOffer extends Model
{
    protected $fillable = [
        'projet_id','total_cached','created_by','updated_by','sent_at'
    ];

    protected $casts = [
        'total_cached' => 'float',
        'sent_at'      => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(FinancialOfferItem::class)
                    ->orderBy('position')->orderBy('id');
    }

    public function projet()
    {
        return $this->belongsTo(Projet::class);
    }
}
