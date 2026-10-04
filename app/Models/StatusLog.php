<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'from_status', 'to_status',
        'event', 'message', 'operator_id', 'context',
    ];

    protected $casts = ['context' => 'array'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
