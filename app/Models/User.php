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
        return $this->hasMany(Projet::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function journalActions()
    {
        return $this->hasMany(JournalAction::class);
    }

    public function isAdmin()
    {
        return $this->role->name === 'Admin Général';
    }

    public function isResponsableAdministratif()
    {
        return $this->role->name === 'Responsable Administratif';
    }
}
