<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    public const TYPE_LABELS = [
        'idcard' => '身份证',
        'residence' => '居住证明',
        'bankcard' => '收款账户凭证',
        'low_income' => '低保证明',
        'heating' => '供暖缴费/方式证明',
        'household_reg' => '户口簿',
    ];

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_RESUBMITTED = 'resubmitted';
    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'application_id', 'doc_type', 'doc_name', 'file_path',
        'issued_on', 'expire_on', 'status',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expire_on' => 'date',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isExpiredOn(Carbon $on): bool
    {
        if ($this->status === self::STATUS_WAIVED) {
            return false;
        }
        return $this->expire_on->endOfDay()->lt($on->startOfDay());
    }

    public function markExpiredIfNeeded(Carbon $on): bool
    {
        if ($this->status === self::STATUS_SUBMITTED && $this->isExpiredOn($on)) {
            $this->update(['status' => self::STATUS_EXPIRED]);
            return true;
        }
        return false;
    }
}
