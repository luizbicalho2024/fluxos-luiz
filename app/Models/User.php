<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Notifiable;

    protected $connection = 'mongodb';
    protected $table = 'users';
    protected $primaryKey = '_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['hashed_password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string { return 'hashed_password'; }
    public function getAuthPassword(): string { return (string) $this->hashed_password; }
    public function isAdmin(): bool { return $this->role === 'admin'; }
}
