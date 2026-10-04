<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    protected $fillable = ['household_id', 'user_id', 'name', 'id_number', 'phone'];

    public function household(): BelongsTo { return $this->belongsTo(Household::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
