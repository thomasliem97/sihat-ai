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

    /**
     * Labs often report censored values (eGFR >60, CRP <0.1). Decimal columns need the number.
     */
    public static function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }

        $text = trim(str_replace([',', ' '], '', (string) $value));
        if ($text === '') {
            return null;
        }

        if (preg_match('/^[<>]=?(-?\d+(?:\.\d+)?)/', $text, $matches) === 1) {
            return (float) $matches[1];
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $text) === 1) {
            return (float) $text;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $text, $matches) === 1) {
            return (float) $matches[0];
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeIncoming(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }

            $value = self::parseDecimal($row['value'] ?? null);
            if ($value === null) {
                continue;
            }

            $row['value'] = $value;
            $row['reference_low'] = self::parseDecimal($row['reference_low'] ?? null);
            $row['reference_high'] = self::parseDecimal($row['reference_high'] ?? null);
            $normalized[] = $row;
        }

        return $normalized;
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
