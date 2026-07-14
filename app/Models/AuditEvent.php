<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $event
 * @property int|null $medical_record_id
 * @property array<string, mixed>|null $payload
 */
class AuditEvent extends Model
{
    protected $fillable = [
        'actor_type',
        'actor_id',
        'event',
        'medical_record_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
