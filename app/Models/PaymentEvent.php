<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    use HasFactory;

    public const DIR_OUTGOING = 'outgoing';
    public const DIR_INCOMING = 'incoming';

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_RECORDED = 'recorded';
    public const RESULT_IGNORED = 'ignored';

    protected $fillable = [
        'direction', 'payment_no', 'receipt_no',
        'disbursement_id', 'payload', 'result',
    ];

    protected $casts = ['payload' => 'array'];

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class);
    }
}
