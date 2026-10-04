<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use HasFactory;

    protected $fillable = [
        'address', 'address_raw', 'household_head_name',
        'household_head_idcard', 'merged_into_id',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'merged_into_id');
    }

    /** 住址标准化：去空格、统一全半角，作为“同一户”判定输入之一 */
    public static function normalizeAddress(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/\s+/u', '', $s);
        // 常见写法归一
        $s = str_replace(['＃', '－', '＿'], ['#', '-', '_'], $s);
        return mb_strtolower($s);
    }
}
