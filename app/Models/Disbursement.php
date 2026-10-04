<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disbursement extends Model
{
    use HasFactory;

    public const STATUS_LISTED = 'listed';
    public const STATUS_PAYING = 'paying';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FROZEN = 'frozen';

    public const STATUS_LABELS = [
        self::STATUS_LISTED => '已入发放清单',
        self::STATUS_PAYING => '支付处理中',
        self::STATUS_PAID => '已到账',
        self::STATUS_FAILED => '支付失败',
        self::STATUS_FROZEN => '冻结暂停',
    ];

    protected $fillable = [
        'batch_id', 'application_id', 'household_id', 'amount',
        'payee_name', 'payee_account', 'status',
        'payment_no', 'receipt_no', 'paid_at', 'receipt_count',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'receipt_count' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
