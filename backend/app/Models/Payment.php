<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'alert_batch_id', 'application_id', 'amount',
        'status', 'transfer_ref', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function batch(): BelongsTo { return $this->belongsTo(AlertBatch::class, 'alert_batch_id'); }
    public function receipts(): HasMany { return $this->hasMany(PaymentReceipt::class); }
}
