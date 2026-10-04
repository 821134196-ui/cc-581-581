<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    protected $fillable = ['payment_id', 'receipt_no', 'outcome', 'payload', 'redelivery_count'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
