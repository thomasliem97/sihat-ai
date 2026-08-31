<?php

namespace Database\Seeders;

use App\Enums\ClinicalFlag;
use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Enums\UserRole;
use App\Models\Biomarker;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;
use App\Services\RagService;
use App\Support\GuidelineIngestor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MedicalDemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'SihatAI-klinik-26';

    public function run(): void
    {
        $this->seedDemoFiles();

        if (User::query()->where('email', 'physician@sihat-ai.vxms.dev')->exists()) {
            return;
        }

        $physician = User::factory()->create([
            'name' => 'Dr. Aisha Rahman',
            'email' => 'physician@sihat-ai.vxms.dev',
            'password' => self::DEMO_PASSWORD,
            'role' => UserRole::Physician,
            'locale' => ReportLanguage::English,
        ]);

        $patient = User::factory()->create([
            'name' => 'Ahmad bin Hassan',
            'email' => 'patient@sihat-ai.vxms.dev',
            'password' => self::DEMO_PASSWORD,
            'role' => UserRole::Patient,
            'locale' => ReportLanguage::Malay,
        ]);

        $this->seedGuidelines();
        $this->seedDemoRecords($physician, $patient);
        $this->seedBiomarkerTrends($patient);
    }

    private function seedDemoFiles(): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('medical-records');

        $copies = [
            public_path('images/chest-xray.png') => 'medical-records/demo-cxr.png',
            base_path('docs/testing-dataset/lab-report/05-hod-healthcare-cbc.pdf') => 'medical-records/demo-lab.pdf',
        ];

        foreach ($copies as $from => $to) {
            if (! is_file($from)) {
                continue;
            }

            $contents = file_get_contents($from);
            if (is_string($contents)) {
                $disk->put($to, $contents);
            }
        }
    }

    private function seedGuidelines(): void
    {
        app(GuidelineIngestor::class)->ingest();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedCompletedRecord(array $attributes): MedicalRecord
    {
        $record = MedicalRecord::create($attributes);
        $findings = $record->findings ?? [];
        $citations = app(RagService::class)->retrieveCitations($record, $findings);
        $existing = is_array($record->physician_report) ? $record->physician_report : [];
        $summary = (string) ($existing['summary'] ?? '');
        $composed = app(AiPipelineService::class)->composeReports($record, [
            'findings' => $findings,
            'overall_confidence' => (float) $record->overall_confidence,
            'citations' => $citations,
            'differential_diagnosis' => $existing['differential_diagnosis'] ?? [],
            'recommendations' => $existing['recommendations'] ?? [],
            'medgemma_draft' => $summary === '' ? '' : "FINDINGS:\n{$summary}\n\nIMPRESSION:\n{$summary}",
            'patient_report' => $record->patient_report,
            'engine' => 'medgemma',
        ], is_array($record->guardrail_flags) ? $record->guardrail_flags : []);

        $record->update([
            'citations' => $citations,
            'physician_report' => $composed['physician_report'],
            'patient_report' => $composed['patient_report'] ?? $record->patient_report,
        ]);

        return $record->fresh();
    }

    private function seedDemoRecords(User $physician, User $patient): void
    {
        $cxr = $this->seedCompletedRecord([
            'user_id' => $patient->id,
            'uploaded_by_user_id' => $physician->id,
            'subject_user_id' => $patient->id,
            'title' => 'Chest X-ray, cough 2 weeks',
            'modality' => Modality::Xray,
            'detected_modality' => Modality::Xray,
            'status' => RecordStatus::Completed,
            'file_path' => 'medical-records/demo-cxr.png',
            'original_filename' => 'cxr_anterior.png',
            'mime_type' => 'image/png',
            'language' => ReportLanguage::Malay,
            'overall_confidence' => 0.84,
            'findings' => [
                ['label' => 'Right lower lobe opacity', 'description' => 'Patchy airspace opacity', 'confidence' => 0.87, 'severity' => 'abnormal'],
                ['label' => 'Cardiomegaly', 'description' => 'CT ratio ~0.55', 'confidence' => 0.72, 'severity' => 'borderline'],
            ],
            'physician_report' => [
                'summary' => 'Right lower lobe opacity with borderline cardiomegaly. DDx: CAP vs TB.',
                'differential_diagnosis' => [
                    ['condition' => 'Community-acquired pneumonia', 'confidence' => 0.78],
                    ['condition' => 'Pulmonary tuberculosis', 'confidence' => 0.62],
                ],
                'recommendations' => ['Sputum AFB if TB suspected', 'Follow-up CXR in 4-6 weeks'],
            ],
            'patient_report' => [
                'summary' => 'Beberapa keputusan memerlukan perhatian doktor anda.',
                'what_this_means' => 'Imej menunjukkan kawasan keruh di bahagian bawah paru-paru kanan.',
                'questions_for_doctor' => ['Adakah saya perlu antibiotik?'],
                'action_plan' => ['Rehat', 'Minum air secukupnya'],
            ],
            'bounding_boxes' => [
                // RLL opacity sits on image-left (patient right) in the lower third of the demo CXR.
                [
                    'label' => 'Right lower lobe opacity',
                    'x' => 0.08,
                    'y' => 0.56,
                    'width' => 0.34,
                    'height' => 0.3,
                    'confidence' => 0.87,
                    'kind' => 'finding',
                    'finding_index' => 0,
                    'image_index' => 0,
                ],
                [
                    'label' => 'Heart',
                    'x' => 0.32,
                    'y' => 0.38,
                    'width' => 0.38,
                    'height' => 0.32,
                    'confidence' => 0.9,
                    'kind' => 'anatomy',
                    'image_index' => 0,
                ],
            ],
            'longitudinal_diff' => [
                'has_prior' => true,
                'summary' => 'New RLL opacity vs prior 3 months ago.',
            ],
            'guardrail_flags' => [
                'code' => 'ALLOW',
                'flags' => ['medical_disclaimer_required', 'not_a_diagnosis', 'confidence_publish'],
            ],
            'pipeline_steps' => [
                ['step' => 'upload', 'label' => 'Upload received', 'status' => 'completed'],
                ['step' => 'analyze', 'label' => 'MedGemma analysis', 'status' => 'completed'],
            ],
            'deidentified_at' => now()->subHours(2),
            'analyzed_at' => now()->subHours(2),
        ]);
        $this->signDemoRecord($cxr, $physician);

        $lab = $this->seedCompletedRecord([
            'user_id' => $patient->id,
            'uploaded_by_user_id' => $patient->id,
            'subject_user_id' => $patient->id,
            'title' => 'Full blood count, routine screening',
            'modality' => Modality::LabPdf,
            'detected_modality' => Modality::LabPdf,
            'status' => RecordStatus::Completed,
            'file_path' => 'medical-records/demo-lab.pdf',
            'original_filename' => 'fbc_report.pdf',
            'mime_type' => 'application/pdf',
            'language' => ReportLanguage::English,
            'overall_confidence' => 0.92,
            'findings' => [
                ['label' => 'Hemoglobin', 'value' => 9.7, 'unit' => 'g/dL', 'severity' => 'abnormal', 'confidence' => 0.95],
                ['label' => 'WBC', 'value' => 14.6, 'unit' => '×10³/µL', 'severity' => 'abnormal', 'confidence' => 0.93],
            ],
            'physician_report' => [
                'summary' => 'Anemia (Hb 9.7 g/dL) with leukocytosis. Low MCV/MCH and raised RDW favor iron deficiency over thalassaemia trait.',
                'differential_diagnosis' => [
                    ['condition' => 'Iron deficiency', 'confidence' => 0.7],
                    ['condition' => 'Thalassaemia trait', 'confidence' => 0.4],
                ],
            ],
            'patient_report' => [
                'summary' => 'Some results need your doctor\'s attention.',
                'what_this_means' => 'Your blood test shows lower than normal hemoglobin.',
            ],
            'guardrail_flags' => [
                'code' => 'ALLOW',
                'flags' => ['medical_disclaimer_required', 'not_a_diagnosis', 'confidence_publish'],
            ],
            'analyzed_at' => now()->subDay(),
        ]);
        $this->signDemoRecord($lab, $physician);
        $this->seedLabBiomarkers($lab, $patient);
    }

    private function signDemoRecord(MedicalRecord $record, User $physician): void
    {
        $record->update([
            'signed_physician_report' => $record->physician_report,
            'signed_by' => $physician->id,
            'signed_at' => $record->analyzed_at ?? now()->subHour(),
        ]);
    }

    private function seedLabBiomarkers(MedicalRecord $record, User $patient): void
    {
        Biomarker::create([
            'user_id' => $patient->id,
            'medical_record_id' => $record->id,
            'name' => 'Hemoglobin',
            'value' => 9.7,
            'unit' => 'g/dL',
            'reference_low' => 12.0,
            'reference_high' => 16.0,
            'status' => ClinicalFlag::Abnormal,
            'collected_at' => $record->analyzed_at ?? now()->subDay(),
        ]);
        Biomarker::create([
            'user_id' => $patient->id,
            'medical_record_id' => $record->id,
            'name' => 'WBC',
            'value' => 14.6,
            'unit' => '×10³/µL',
            'reference_low' => 4.0,
            'reference_high' => 11.0,
            'status' => ClinicalFlag::Abnormal,
            'collected_at' => $record->analyzed_at ?? now()->subDay(),
        ]);
    }

    private function seedBiomarkerTrends(User $patient): void
    {
        $dates = [now()->subMonths(6), now()->subMonths(4), now()->subMonths(2), now()];

        foreach ($dates as $i => $date) {
            Biomarker::create([
                'user_id' => $patient->id,
                'name' => 'Hemoglobin',
                'value' => 11.5 - ($i * 0.6),
                'unit' => 'g/dL',
                'reference_low' => 12.0,
                'reference_high' => 16.0,
                'status' => $i >= 2 ? ClinicalFlag::Abnormal : ClinicalFlag::Borderline,
                'collected_at' => $date,
            ]);
        }
    }
}
