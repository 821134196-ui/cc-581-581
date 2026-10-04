<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id', 'low_temp_threshold', 'allowed_heating_methods',
        'doc_valid_days', 'required_documents', 'subsidy_amount',
        'subsidy_unit', 'residency_requirement', 'version',
    ];

    protected $casts = [
        'allowed_heating_methods' => 'array',
        'required_documents' => 'array',
        'subsidy_amount' => 'decimal:2',
        'low_temp_threshold' => 'integer',
        'doc_valid_days' => 'integer',
        'version' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** 生成冻结快照（随申请保存，审核始终读这份） */
    public function toSnapshot(): array
    {
        return [
            'version' => $this->version,
            'low_temp_threshold' => $this->low_temp_threshold,
            'allowed_heating_methods' => $this->allowed_heating_methods,
            'doc_valid_days' => $this->doc_valid_days,
            'required_documents' => $this->required_documents,
            'subsidy_amount' => $this->subsidy_amount,
            'subsidy_unit' => $this->subsidy_unit,
            'residency_requirement' => $this->residency_requirement,
        ];
    }

    /** 材料到期日：签发日 + 有效天数 */
    public function expiryFor(\DateTimeInterface $issuedOn): string
    {
        return \Carbon\Carbon::parse($issuedOn)->addDays($this->doc_valid_days)->toDateString();
    }
}
