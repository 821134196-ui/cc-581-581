<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    public const STATUSES = [
        'submitted' => '待社区初审',
        'supplementing' => '待居民补件',
        'community_rejected' => '初审不通过',
        'pending_street' => '待街道复核',
        'street_rejected' => '复核不通过',
        'approved' => '已核准，待发放',
        'paid' => '已到账',
        'merged' => '已并入同户其他申请',
    ];

    protected $fillable = [
        'alert_batch_id', 'household_id', 'applicant_user_id', 'status',
        'is_late', 'rule_snapshot', 'payload',
        'payee_name', 'payee_account', 'payee_bank', 'payee_verified',
        'frozen', 'merged_into_id',
    ];

    protected function casts(): array
    {
        return [
            'is_late' => 'boolean',
            'rule_snapshot' => 'array',
            'payload' => 'array',
            'payee_verified' => 'boolean',
            'frozen' => 'boolean',
        ];
    }

    public function batch(): BelongsTo { return $this->belongsTo(AlertBatch::class, 'alert_batch_id'); }
    public function household(): BelongsTo { return $this->belongsTo(Household::class); }
    public function applicant(): BelongsTo { return $this->belongsTo(User::class, 'applicant_user_id'); }
    public function members(): BelongsToMany { return $this->belongsToMany(Member::class); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function appeals(): HasMany { return $this->hasMany(Appeal::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }
    public function events(): HasMany { return $this->hasMany(ApplicationEvent::class)->orderByDesc('id'); }
    public function payment(): HasOne { return $this->hasOne(Payment::class); }
    public function payeeChangeRequests(): HasMany { return $this->hasMany(PayeeChangeRequest::class); }
    public function mergedInto(): BelongsTo { return $this->belongsTo(self::class, 'merged_into_id'); }

    public function communityReview(): ?Review
    {
        return $this->reviews->firstWhere('stage', 'community');
    }
}
