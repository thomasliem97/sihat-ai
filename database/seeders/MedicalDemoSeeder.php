<?php

namespace Database\Seeders;

use App\Enums\ClinicalFlag;
use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Enums\UserRole;
use App\Models\Biomarker;
use App\Models\EvalRun;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class MedicalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $physician = User::factory()->create([
            'name' => 'Dr. Aisha Rahman',
            'email' => 'physician@sihat-ai.vxms.dev',
            'password' => Hash::make('password'),
            'role' => UserRole::Physician,
            'locale' => ReportLanguage::English,
        ]);

        $patient = User::factory()->create([
            'name' => 'Ahmad bin Hassan',
            'email' => 'patient@sihat-ai.vxms.dev',
            'password' => Hash::make('password'),
            'role' => UserRole::Patient,
            'locale' => ReportLanguage::Malay,
        ]);

        $this->seedDemoFiles();
        $this->seedGuidelines();
        $this->seedEvalRuns();
        $this->seedDemoRecords($physician, $patient);
        $this->seedBiomarkerTrends($patient);
    }

    private function seedDemoFiles(): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('medical-records');

        $cxr = public_path('images/chest-xray.png');
        if (is_file($cxr)) {
            $disk->put('medical-records/demo-cxr.png', file_get_contents($cxr));
        }

        // Minimal one-page PDF for the lab demo specimen.
        $disk->put('medical-records/demo-lab.pdf', <<<'PDF'
%PDF-1.4
1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj
2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj
3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj
4 0 obj<< /Length 68 >>stream
BT /F1 18 Tf 72 720 Td (SihatAI demo FBC report) Tj ET
endstream
endobj
5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj
xref
0 6
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000266 00000 n 
0000000385 00000 n 
trailer<< /Size 6 /Root 1 0 R >>
startxref
462
%%EOF
PDF);
    }

    private function seedGuidelines(): void
    {
        $rag = app(RagService::class);

        $rows = [
            [
                'source' => 'MOH Malaysia CPG - Community Acquired Pneumonia',
                'section' => '4.2 Diagnosis',
                'content' => 'Chest radiograph may show lobar or patchy consolidation. Clinical correlation with symptoms, vital signs, and laboratory findings is essential before initiating antibiotic therapy.',
            ],
            [
                'source' => 'MOH Malaysia CPG - Tuberculosis',
                'section' => '3.1 Imaging',
                'content' => 'Upper lobe cavitary lesions are characteristic of post-primary TB. Lower lobe involvement can occur, particularly in immunocompromised patients.',
            ],
            [
                'source' => 'MOH Malaysia CPG - Thalassemia',
                'section' => '2.1 Laboratory',
                'content' => 'Microcytic hypochromic anemia with elevated HbA2 or HbF suggests beta-thalassemia trait. Iron studies help differentiate from iron deficiency anemia.',
            ],
            [
                'source' => 'MOH Malaysia CPG - Dermatology',
                'section' => '1.2 Pigmented lesions',
                'content' => 'Asymmetry, border irregularity, colour variation, diameter >6mm, and evolution warrant specialist review for suspected melanoma.',
            ],
        ];

        foreach ($rows as $row) {
            GuidelineChunk::create([
                ...$row,
                'embedding' => $rag->localHashEmbed($row['source'].' '.$row['section'].' '.$row['content']),
            ]);
        }
    }

    private function seedEvalRuns(): void
    {
        EvalRun::create([
            'run_type' => 'medqa',
            'sample_count' => 1273,
            'avg_score' => 68.4,
            'metrics' => [
                'accuracy' => 0.684,
                'f1_macro' => 0.671,
                'categories' => ['anatomy' => 0.72, 'diagnosis' => 0.65, 'treatment' => 0.69],
                'demo_seed' => true,
            ],
        ]);

        EvalRun::create([
            'run_type' => 'llm_judge',
            'sample_count' => 150,
            'avg_score' => 4.2,
            'metrics' => [
                'clarity' => 4.3,
                'accuracy' => 4.1,
                'grounding' => 4.4,
                'safety' => 4.5,
                'scale' => '1-5',
                'demo_seed' => true,
            ],
        ]);

        EvalRun::create([
            'run_type' => 'safety',
            'sample_count' => 200,
            'avg_score' => 96.5,
            'metrics' => [
                'disclaimer_rate' => 1.0,
                'critical_escalation_rate' => 0.98,
                'diagnosis_refusal_rate' => 0.95,
                'demo_seed' => true,
            ],
        ]);
    }

    private function seedDemoRecords(User $physician, User $patient): void
    {
        MedicalRecord::create([
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
            'citations' => [
                ['source' => 'MOH CPG - CAP', 'section' => '4.2', 'excerpt' => 'Chest radiograph may show patchy consolidation.', 'relevance' => 0.82],
            ],
            'bounding_boxes' => [
                // RLL opacity sits on image-left (patient right) in the lower third of the demo CXR.
                ['label' => 'Opacity', 'x' => 0.08, 'y' => 0.56, 'width' => 0.34, 'height' => 0.3, 'confidence' => 0.87],
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

        MedicalRecord::create([
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
                ['label' => 'Hemoglobin', 'value' => 9.2, 'unit' => 'g/dL', 'severity' => 'abnormal', 'confidence' => 0.95],
                ['label' => 'Platelet count', 'value' => 85, 'unit' => '×10³/µL', 'severity' => 'abnormal', 'confidence' => 0.93],
            ],
            'physician_report' => [
                'summary' => 'Microcytic anemia with thrombocytopenia. Consider thalassemia trait vs iron deficiency.',
            ],
            'patient_report' => [
                'summary' => 'Some results need your doctor\'s attention.',
                'what_this_means' => 'Your blood test shows lower than normal hemoglobin and platelets.',
            ],
            'guardrail_flags' => [
                'code' => 'ALLOW',
                'flags' => ['medical_disclaimer_required', 'not_a_diagnosis', 'confidence_publish'],
            ],
            'analyzed_at' => now()->subDay(),
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
