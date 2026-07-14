<?php

namespace App\Models;

use App\Enums\ClinicalFlag;
use Database\Factories\BiomarkerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $medical_record_id
 * @property string $name
 * @property float $value
 * @property string $unit
 * @property float|null $reference_low
 * @property float|null $reference_high
 * @property ClinicalFlag $status
 * @property Carbon $collected_at
 */
class Biomarker extends Model
{
    /** @use HasFactory<BiomarkerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'medical_record_id',
        'name',
        'value',
        'unit',
        'reference_low',
        'reference_high',
        'status',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'reference_low' => 'float',
            'reference_high' => 'float',
            'status' => ClinicalFlag::class,
            'collected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }
}
