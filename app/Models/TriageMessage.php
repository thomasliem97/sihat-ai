<?php

namespace App\Models;

use App\Enums\TriageInputModality;
use App\Enums\TriageMessageRole;
use Database\Factories\TriageMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $triage_session_id
 * @property TriageMessageRole $role
 * @property string $content
 * @property TriageInputModality $input_modality
 * @property string|null $stt_engine
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TriageMessage extends Model
{
    /** @use HasFactory<TriageMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'triage_session_id',
        'role',
        'content',
        'input_modality',
        'stt_engine',
    ];

    protected function casts(): array
    {
        return [
            'role' => TriageMessageRole::class,
            'input_modality' => TriageInputModality::class,
        ];
    }

    /** @return BelongsTo<TriageSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TriageSession::class, 'triage_session_id');
    }
}
