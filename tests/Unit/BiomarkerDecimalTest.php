<?php

use App\Models\Biomarker;

test('parseDecimal reads censored lab values', function (mixed $in, ?float $out) {
    expect(Biomarker::parseDecimal($in))->toBe($out);
})->with([
    ['>60', 60.0],
    ['<0.1', 0.1],
    ['>=90', 90.0],
    ['6.8', 6.8],
    [6.8, 6.8],
    ['> 60 mL/min', 60.0],
    ['ND', null],
    ['', null],
    [null, null],
]);

test('normalizeIncoming keeps numeric rows and drops non-numeric', function () {
    $rows = Biomarker::normalizeIncoming([
        [
            'name' => 'eGFR',
            'value' => '>60',
            'unit' => 'mL/min',
            'reference_low' => '>60',
            'reference_high' => '',
            'status' => 'normal',
        ],
        [
            'name' => 'CRP',
            'value' => 'ND',
            'unit' => 'mg/L',
            'status' => 'normal',
        ],
        [
            'name' => 'Hemoglobin',
            'value' => '13.3',
            'unit' => 'g/dL',
            'reference_low' => '11.5',
            'reference_high' => '16.5',
            'status' => 'normal',
        ],
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('eGFR')
        ->and($rows[0]['value'])->toBe(60.0)
        ->and($rows[0]['reference_low'])->toBe(60.0)
        ->and($rows[0]['reference_high'])->toBeNull()
        ->and($rows[1]['name'])->toBe('Hemoglobin')
        ->and($rows[1]['value'])->toBe(13.3);
});
