<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    public const LEVEL_COMMUNITY = 'community';
    public const LEVEL_STREET = 'street';

    public const RESULT_PASS = 'pass';
    public const RESULT_REJECT = 'reject';
    public const RESULT_SUPPLEMENT = 'supplement';
    public const RESULT_REVERIFY = 'reverify';

    protected $fillable = [
        'application_id', 'level', 'reviewer_id',
        'result', 'comment', 'checked_items',
    ];

    protected $casts = [
        'checked_items' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
