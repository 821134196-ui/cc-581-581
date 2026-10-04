<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SUPPLEMENT_REQUESTED = 'supplement_requested';
    public const STATUS_COMMUNITY_REJECTED = 'community_rejected';
    public const STATUS_STREET_PENDING = 'street_pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPEALING = 'appealing';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_DISBURSED = 'disbursed';

    /** 居民可见的中文状态 */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_SUBMITTED => '已提交，待社区初审',
        self::STATUS_SUPPLEMENT_REQUESTED => '材料待补充',
        self::STATUS_COMMUNITY_REJECTED => '初审不通过',
        self::STATUS_STREET_PENDING => '待街道复核',
        self::STATUS_APPROVED => '复核通过，待发放',
        self::STATUS_REJECTED => '复核不通过',
        self::STATUS_APPEALING => '申诉处理中',
        self::STATUS_FROZEN => '已冻结，待重新核验',
        self::STATUS_DISBURSED => '补贴已到账',
    ];

    protected $fillable = [
        'apply_no', 'batch_id', 'household_id', 'applicant_id',
        'rule_snapshot', 'payload_snapshot', 'heating_method', 'address',
        'is_late', 'status',
        'frozen_by', 'frozen_at', 'freeze_reason',
        'merged', 'merged_into_id',
        'status_before_freeze', 'reverify_required',
    ];

    protected $casts = [
        'rule_snapshot' => 'array',
        'payload_snapshot' => 'array',
        'is_late' => 'boolean',
        'merged' => 'boolean',
        'reverify_required' => 'boolean',
        'frozen_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function communityReview(): HasOne
    {
        return $this->hasOne(Review::class)->where('level', Review::LEVEL_COMMUNITY)->latestOfMany();
    }

    public function streetReview(): HasOne
    {
        return $this->hasOne(Review::class)->where('level', Review::LEVEL_STREET)->latestOfMany();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function disbursement(): HasOne
    {
        return $this->hasOne(Disbursement::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(StatusLog::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 当前收款信息（补件后可能更新；冻结期间变更触发重新核验） */
    public function payee(): array
    {
        $p = $this->payload_snapshot;
        return [
            'name' => $p['payee']['name'] ?? null,
            'account' => $p['payee']['account'] ?? null,
            'bank' => $p['payee']['bank'] ?? null,
        ];
    }

    public function subsidyAmount(): string
    {
        return (string) ($this->rule_snapshot['subsidy_amount'] ?? '0');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMMUNITY_REJECTED,
            self::STATUS_REJECTED,
            self::STATUS_DISBURSED,
        ], true);
    }
}
