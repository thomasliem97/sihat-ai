<?php

use App\Support\LabTextExtractor;
use Illuminate\Support\Facades\Process;

test('extracts a digital lab pdf via pdftotext', function () {
    Process::fake([
        '*' => Process::result(output: "Haemoglobin  13.3  g/dL  11.5-16.5\nWBC  12.1  x10^9/L  4.0-11.0\n"),
    ]);

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sihat-lab-extract.pdf';
    file_put_contents($path, '%PDF-1.4 test');

    $text = app(LabTextExtractor::class)->extractFrom($path, 'application/pdf', 'cbc.pdf');

    expect($text)->toContain('Haemoglobin')
        ->and($text)->toContain('WBC');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return is_array($command) && in_array('-table', $command, true);
    });

    unlink($path);
});

test('returns empty when the pdf text layer is too thin', function () {
    Process::fake([
        '*' => Process::result(output: 'short'),
    ]);

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sihat-lab-thin.pdf';
    file_put_contents($path, '%PDF-1.4');

    expect(app(LabTextExtractor::class)->extractFrom($path, 'application/pdf', 'scan.pdf'))->toBe('');

    unlink($path);
});

test('skips non-pdf files so Modal can OCR photos', function () {
    Process::fake();

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sihat-lab.jpg';
    file_put_contents($path, 'jpeg');

    expect(app(LabTextExtractor::class)->extractFrom($path, 'image/jpeg', 'cbc.jpg'))->toBe('');

    Process::assertNothingRan();

    unlink($path);
});
