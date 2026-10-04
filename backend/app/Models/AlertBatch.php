<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertBatch extends Model
{
    protected $fillable = [
        'name', 'level', 'started_at', 'ended_at', 'is_accepting',
        'late_filing_deadline', 'subsidy_amount', 'rules',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'late_filing_deadline' => 'date',
            'is_accepting' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function applications(): HasMany { return $this->hasMany(Application::class); }

    /** 预警是否已结束 */
    public function isEnded(\DateTimeInterface $at): bool
    {
        return $this->ended_at !== null && $this->ended_at->isBefore($at);
    }

    /** 补报是否仍在期限内 */
    public function acceptsLateFiling(\DateTimeInterface $at): bool
    {
        if (!$this->is_accepting) {
            return false;
        }
        if (!$this->isEnded($at)) {
            return true;
        }
        return $this->late_filing_deadline !== null
            && \Illuminate\Support\Carbon::instance($at)->startOfDay()->lte($this->late_filing_deadline);
    }
}
