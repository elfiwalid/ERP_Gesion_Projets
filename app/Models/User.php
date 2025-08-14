<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'photo',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    
public function projets()
{
    // préciser la FK non conventionnelle
    return $this->hasMany(Projet::class, 'cree_par');
}

public function demandes()
{
    // préciser la FK non conventionnelle
    return $this->hasMany(Demande::class, 'cree_par');
}



    public function isAdmin()
    {
        return $this->role->name === 'Admin Général';
    }

    public function isResponsableAdministratif()
    {
        return $this->role->name === 'Responsable Administratif';

   }





    // (optionnel, pratique)
    public function uploadedDocuments()
    {
        return $this->hasMany(DemandeDocument::class, 'uploaded_by');
    }

    public function uploadedPieces()
    {
        return $this->hasMany(Piece::class, 'uploaded_by');
    }

    public function assignedPieces()
    {
        return $this->hasMany(Piece::class, 'assigned_user_id');
    }

    public function assignmentsMade()
    {
        return $this->hasMany(Piece::class, 'assigned_by');
    }



    
}
