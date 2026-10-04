<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_RESIDENT = 'resident';
    public const ROLE_COMMUNITY = 'community';
    public const ROLE_STREET = 'street';

    protected $fillable = [
        'name', 'email', 'password',
        'role', 'phone', 'employee_no',
        'household_id', 'idcard', 'is_household_head',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_household_head' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'applicant_id');
    }

    public function isResident(): bool
    {
        return $this->role === self::ROLE_RESIDENT;
    }

    public function isCommunity(): bool
    {
        return $this->role === self::ROLE_COMMUNITY;
    }

    public function isStreet(): bool
    {
        return $this->role === self::ROLE_STREET;
    }
}
