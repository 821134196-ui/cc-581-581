<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'title', 'warning_start', 'warning_end',
        'apply_deadline', 'late_apply_deadline',
        'rules_frozen', 'rules_frozen_at', 'rules_frozen_by', 'status',
    ];

    protected $casts = [
        'warning_start' => 'date',
        'warning_end' => 'date',
        'apply_deadline' => 'date',
        'late_apply_deadline' => 'date',
        'rules_frozen' => 'boolean',
        'rules_frozen_at' => 'datetime',
    ];

    public function rule(): HasOne
    {
        return $this->hasOne(EligibilityRule::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    /** 正常受理期内 */
    public function isWithinApplyWindow(\DateTimeInterface $on): bool
    {
        return $on <= $this->apply_deadline->endOfDay();
    }

    /** 预警结束后的补报窗口（按本批规则判断） */
    public function isWithinLateWindow(\DateTimeInterface $on): bool
    {
        return $on > $this->warning_end->endOfDay()
            && $on <= $this->late_apply_deadline->endOfDay();
    }

    public function isAccepting(\DateTimeInterface $on): bool
    {
        return $this->status === 'open' && $on <= $this->late_apply_deadline->endOfDay();
    }
}
