<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayeeChangeRequest extends Model
{
    protected $fillable = [
        'application_id', 'payee_name', 'payee_account', 'payee_bank',
        'status', 'reviewer_id', 'note',
    ];

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class); }
}
