<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'phone', 'role', 'password', 'api_token'];
    protected $hidden = ['password', 'api_token', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public static function boot(): void
    {
        parent::boot();
        static::creating(function (User $user) {
            if (blank($user->api_token)) {
                $user->api_token = Str::random(60);
            }
        });
    }

    public function isResident(): bool { return $this->role === 'resident'; }
    public function isCommunity(): bool { return $this->role === 'community'; }
    public function isStreet(): bool { return $this->role === 'street'; }

    public function submittedApplications(): HasMany
    {
        return $this->hasMany(Application::class, 'applicant_user_id');
    }
}
