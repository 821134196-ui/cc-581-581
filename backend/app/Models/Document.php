<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = [
        'application_id', 'doc_type', 'label', 'status',
        'file_ref', 'expires_at', 'submitted_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }

    /** 材料是否已过期（含提交后被标记过期） */
    public function isExpired(\DateTimeInterface $at): bool
    {
        return $this->expires_at !== null
            && $this->status === 'submitted'
            && $this->expires_at->isBefore($at);
    }
}
