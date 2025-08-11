<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
  protected $fillable = [
    'projet_id','name','type','path','status','validation_gate',
    'shared_with','uploaded_by','reviewed_by','reviewed_at','review_comment'
  ];
  protected $casts = [
    'shared_with' => 'array',
    'reviewed_at' => 'datetime',
  ];

  public function projet(): BelongsTo { return $this->belongsTo(Projet::class); }
  public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class,'uploaded_by'); }
  public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }
}
