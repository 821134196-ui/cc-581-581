<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    protected $fillable = ['application_id', 'user_id', 'reason', 'status', 'handler_id', 'reply', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
