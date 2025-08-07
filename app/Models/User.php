<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * Campos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Campos que deben ocultarse en serialización
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Tipos de datos para casting automático
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Obtener identificador para JWT
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Obtener claims personalizados para JWT
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}