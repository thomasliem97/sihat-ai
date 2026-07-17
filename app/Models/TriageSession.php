<?php

namespace App\Models;

use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Enums\TriageUrgency;
use Database\Factories\TriageSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $subject_user_id
 * @property TriageRoleContext $role_context
 * @property string $locale
 * @property TriageSessionStatus $status
 * @property TriageUrgency|null $urgency
 * @property string|null $chief_complaint
 * @property string|null $summary
 * @property Carbon|null $shared_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TriageSession extends Model
{
    /** @use HasFactory<TriageSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject_user_id',
        'role_context',
        'locale',
        'status',
        'urgency',
        'chief_complaint',
        'summary',
        'shared_at',
    ];

    protected function casts(): array
    {
        return [
            'role_context' => TriageRoleContext::class,
            'status' => TriageSessionStatus::class,
            'urgency' => TriageUrgency::class,
            'shared_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return HasMany<TriageMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TriageMessage::class)->orderBy('id');
    }
}
