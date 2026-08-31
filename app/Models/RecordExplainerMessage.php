<?php

namespace App\Models;

use App\Enums\ExplainerMessageRole;
use Database\Factories\RecordExplainerMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $medical_record_id
 * @property int $user_id
 * @property ExplainerMessageRole $role
 * @property string $content
 * @property int|null $finding_index
 * @property array<string, mixed>|null $selected_box
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecordExplainerMessage extends Model
{
    /** @use HasFactory<RecordExplainerMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'medical_record_id',
        'user_id',
        'role',
        'content',
        'finding_index',
        'selected_box',
    ];

    protected function casts(): array
    {
        return [
            'role' => ExplainerMessageRole::class,
            'finding_index' => 'integer',
            'selected_box' => 'array',
        ];
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
