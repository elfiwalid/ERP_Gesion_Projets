<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialOfferItem extends Model
{
    protected $fillable = [
        'financial_offer_id','label','amount','position','created_by','updated_by'
    ];

    protected $casts = [
        'amount'   => 'float',
        'position' => 'integer',
    ];

    public function offer()
    {
        return $this->belongsTo(FinancialOffer::class, 'financial_offer_id');
    }
}
