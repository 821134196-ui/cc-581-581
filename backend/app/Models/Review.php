<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = ['application_id', 'stage', 'reviewer_id', 'decision', 'note'];

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class); }
}
