<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = ['address', 'address_hash'];

    public static function normalizeAddress(string $address): string
    {
        $s = preg_replace('/\s+/u', '', trim($address));
        return str_replace('＃', '#', $s);
    }

    public static function hashAddress(string $address): string
    {
        return hash('sha256', self::normalizeAddress($address));
    }

    public static function firstOrCreateByAddress(string $address): self
    {
        return self::firstOrCreate(
            ['address_hash' => self::hashAddress($address)],
            ['address' => trim($address)],
        );
    }

    public function members(): HasMany { return $this->hasMany(Member::class); }
    public function applications(): HasMany { return $this->hasMany(Application::class); }
}
