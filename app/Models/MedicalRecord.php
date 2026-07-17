<?php

namespace App\Models;

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $uploaded_by_user_id
 * @property int|null $subject_user_id
 * @property string $title
 * @property Modality $modality
 * @property Modality|null $detected_modality
 * @property float|null $route_confidence
 * @property RecordStatus $status
 * @property string $file_path
 * @property string|null $safe_file_path
 * @property string $original_filename
 * @property string $mime_type
 * @property ReportLanguage $language
 * @property float|null $overall_confidence
 * @property array<string, mixed>|null $findings
 * @property array<string, mixed>|null $partial_findings
 * @property array<string, mixed>|null $physician_report
 * @property array<string, mixed>|null $patient_report
 * @property array<int, mixed>|null $citations
 * @property array<int, mixed>|null $bounding_boxes
 * @property array<string, mixed>|null $longitudinal_diff
 * @property array<string, mixed>|null $guardrail_flags
 * @property array<int, mixed>|null $pipeline_steps
 * @property array<int, mixed>|null $agent_trace
 * @property array<int, float>|null $findings_embedding
 * @property array<string, mixed>|null $volume_meta
 * @property array<string, mixed>|null $patch_meta
 * @property array<string, mixed>|null $signed_physician_report
 * @property int|null $signed_by
 * @property Carbon|null $signed_at
 * @property string|null $error_message
 * @property Carbon|null $deidentified_at
 * @property Carbon|null $analyzed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MedicalRecord extends Model
{
    /** @use HasFactory<MedicalRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'uploaded_by_user_id',
        'subject_user_id',
        'title',
        'modality',
        'detected_modality',
        'route_confidence',
        'status',
        'file_path',
        'safe_file_path',
        'original_filename',
        'mime_type',
        'language',
        'overall_confidence',
        'findings',
        'partial_findings',
        'physician_report',
        'patient_report',
        'citations',
        'bounding_boxes',
        'longitudinal_diff',
        'guardrail_flags',
        'pipeline_steps',
        'agent_trace',
        'findings_embedding',
        'volume_meta',
        'patch_meta',
        'signed_physician_report',
        'signed_by',
        'signed_at',
        'error_message',
        'deidentified_at',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'modality' => Modality::class,
            'detected_modality' => Modality::class,
            'status' => RecordStatus::class,
            'language' => ReportLanguage::class,
            'overall_confidence' => 'float',
            'route_confidence' => 'float',
            'findings' => 'array',
            'partial_findings' => 'array',
            'physician_report' => 'array',
            'patient_report' => 'array',
            'citations' => 'array',
            'bounding_boxes' => 'array',
            'longitudinal_diff' => 'array',
            'guardrail_flags' => 'array',
            'pipeline_steps' => 'array',
            'agent_trace' => 'array',
            'findings_embedding' => 'array',
            'volume_meta' => 'array',
            'patch_meta' => 'array',
            'signed_physician_report' => 'array',
            'deidentified_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * Normalize guardrail payload to {code, flags}. Accepts legacy flat flag lists.
     *
     * @param  array<int|string, mixed>|null  $guardrails
     * @return array{code: string, flags: list<string>}
     */
    public static function normalizeGuardrails(?array $guardrails): array
    {
        $guardrails ??= [];

        if (isset($guardrails['flags']) && is_array($guardrails['flags'])) {
            /** @var list<string> $flags */
            $flags = array_values(array_filter($guardrails['flags'], 'is_string'));
            $code = is_string($guardrails['code'] ?? null) ? $guardrails['code'] : 'ALLOW';

            return ['code' => $code, 'flags' => $flags];
        }

        /** @var list<string> $flags */
        $flags = array_values(array_filter($guardrails, 'is_string'));
        $warn = in_array('critical_value_escalation', $flags, true)
            || in_array('low_confidence_abstention', $flags, true)
            || in_array('weak_guideline_grounding', $flags, true);

        return ['code' => $warn ? 'WARN' : 'ALLOW', 'flags' => $flags];
    }

    /** @return list<string> */
    public function guardrailFlagList(): array
    {
        return self::normalizeGuardrails($this->guardrail_flags)['flags'];
    }

    public function guardrailCode(): string
    {
        return self::normalizeGuardrails($this->guardrail_flags)['code'];
    }

    public function inferenceFilePath(): string
    {
        return $this->safe_file_path ?: $this->file_path;
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function patientDisplayName(): string
    {
        if ($this->subject_user_id !== null) {
            return $this->subjectUser->name;
        }

        return $this->user->isPatient() ? $this->user->name : 'Unassigned';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** @return HasMany<Biomarker, $this> */
    public function biomarkers(): HasMany
    {
        return $this->hasMany(Biomarker::class);
    }

    /** @return HasOne<AnalysisJob, $this> */
    public function analysisJob(): HasOne
    {
        return $this->hasOne(AnalysisJob::class)->latestOfMany();
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
