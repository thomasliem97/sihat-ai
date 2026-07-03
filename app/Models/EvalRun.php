<?php

namespace App\Models;

use Database\Factories\EvalRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $run_type
 * @property int $sample_count
 * @property float|null $avg_score
 * @property array<string, mixed>|null $metrics
 */
class EvalRun extends Model
{
    /** @use HasFactory<EvalRunFactory> */
    use HasFactory;

    protected $fillable = [
        'run_type',
        'sample_count',
        'avg_score',
        'metrics',
    ];

    protected function casts(): array
    {
        return [
            'avg_score' => 'float',
            'metrics' => 'array',
        ];
    }
}
