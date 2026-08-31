<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\MedicalRecord;
use App\Models\User;
use Database\Seeders\MedicalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('copies the testing-dataset cbc pdf into demo storage', function () {
    Storage::fake('local');

    $source = base_path('docs/testing-dataset/lab-report/05-hod-healthcare-cbc.pdf');
    expect(is_file($source))->toBeTrue();

    (new ReflectionMethod(MedicalDemoSeeder::class, 'seedDemoFiles'))
        ->invoke(new MedicalDemoSeeder);

    expect(Storage::disk('local')->get('medical-records/demo-lab.pdf'))
        ->toBe(file_get_contents($source))
        ->toStartWith('%PDF');
});

test('demo seeder signs records and attaches lab biomarkers', function () {
    $this->seed(MedicalDemoSeeder::class);

    $records = MedicalRecord::query()->get();
    expect($records)->toHaveCount(2)
        ->and($records->every(fn (MedicalRecord $record) => $record->isSigned()))->toBeTrue();

    $lab = $records->firstWhere('modality', Modality::LabPdf);
    expect($lab)->not->toBeNull()
        ->and($lab->status)->toBe(RecordStatus::Completed)
        ->and($lab->biomarkers)->toHaveCount(2);
});

test('demo seeder is idempotent', function () {
    $this->seed(MedicalDemoSeeder::class);
    $this->seed(MedicalDemoSeeder::class);

    expect(User::query()->count())->toBe(2)
        ->and(MedicalRecord::query()->count())->toBe(2);
});

test('demo seeder resumes when users exist but records do not', function () {
    User::factory()->physician()->create([
        'email' => 'physician@sihat-ai.vxms.dev',
        'password' => MedicalDemoSeeder::DEMO_PASSWORD,
    ]);
    User::factory()->patient()->create([
        'email' => 'patient@sihat-ai.vxms.dev',
        'password' => MedicalDemoSeeder::DEMO_PASSWORD,
    ]);

    $this->seed(MedicalDemoSeeder::class);

    expect(User::query()->count())->toBe(2)
        ->and(MedicalRecord::query()->count())->toBe(2);
});
