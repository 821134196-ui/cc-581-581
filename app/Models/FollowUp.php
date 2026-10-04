<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    use HasFactory;

    public const TYPE_SUPPLEMENT = 'supplement';
    public const TYPE_APPEAL = 'appeal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'application_id', 'type', 'user_id', 'reason',
        'extra_payload', 'status', 'handle_comment', 'handled_by', 'handled_at',
    ];

    protected $casts = [
        'extra_payload' => 'array',
        'handled_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
