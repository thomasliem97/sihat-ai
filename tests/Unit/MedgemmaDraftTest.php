<?php

use App\Support\MedgemmaDraft;

test('splits findings and impression from a medgemma draft', function () {
    $split = MedgemmaDraft::split(
        "FINDINGS:\nLungs are clear. No pneumothorax or effusion.\nHeart size is normal.\n\nIMPRESSION:\nNo acute cardiopulmonary process."
    );

    expect($split['findings'])->toContain('No pneumothorax')
        ->and($split['findings'])->toContain('Heart size is normal')
        ->and($split['impression'])->toContain('No acute cardiopulmonary');
});

test('keeps the full draft as findings when headings are missing', function () {
    $split = MedgemmaDraft::split('Patchy right lower lobe opacity. Correlate clinically.');

    expect($split['findings'])->toBe('Patchy right lower lobe opacity. Correlate clinically.')
        ->and($split['impression'])->toBe('');
});

test('hides a previously saved prompt leak on the record', function () {
    $echo = "FINDINGS:\nSystematic review of every region in view. Include pertinent negatives as well as abnormalities. Do not collapse this into a short list of labels.\n\nIMPRESSION:\nThe overall clinical interpretation and next step.";

    expect(MedgemmaDraft::isPromptEcho($echo))->toBeTrue()
        ->and(MedgemmaDraft::scrubReport([
            'findings_narrative' => $echo,
            'impression' => 'The overall clinical interpretation and next step.',
            'summary' => $echo,
        ])['findings_narrative'])->toBe('');
});

test('a full regional chest report is not treated as a prompt leak', function () {
    $draft = <<<'TXT'
FINDINGS:
Frontal chest radiograph. Inspiration is adequate and there is no rotation. The lungs show patchy airspace opacity in the right lower zone with air bronchograms. The left lung is clear. There is no pneumothorax. Costophrenic angles are sharp. Heart size is within normal limits. No devices are seen.

IMPRESSION:
1. Right lower zone consolidation, most in keeping with pneumonia in the appropriate clinical context.
2. Recommend clinical correlation with fever and white cell count. Follow-up radiograph after treatment if symptoms persist.
TXT;

    expect(MedgemmaDraft::isPromptEcho($draft))->toBeFalse()
        ->and(MedgemmaDraft::split($draft)['impression'])->toContain('Right lower zone consolidation');
});

test('cleanLab strips dash hash padding and a second findings block', function () {
    $raw = <<<'TXT'
FINDINGS:
ESR 40 mm/hr (H). FBC otherwise in range.

IMPRESSION:
Raised ESR; correlate clinically.
-.
-.
####
Final Report End.<unused95>FINDINGS:
again
TXT;

    $clean = MedgemmaDraft::cleanLab($raw);

    expect($clean)->toContain('ESR 40')
        ->and($clean)->toContain('Raised ESR')
        ->and($clean)->not->toContain('####')
        ->and($clean)->not->toContain('again')
        ->and($clean)->not->toContain('<unused');
});

test('cleanLab cuts a cycling numbered impression', function () {
    $raw = <<<'TXT'
FINDINGS:
1) Technical quality: diagnostic PA radiograph.
2) Lungs: nodular opacities in the right upper lobe, right lower lobe, and left lower lobe.

IMPRESSION:
1) Nodular opacities in the right upper lobe, right lower lobe, and left lower lobe. Recommend CT chest for further evaluation.
2) No pneumothorax or pleural effusion.
3) No cardiomegaly.
4) No consolidation.
5) No interstitial change.
6) No mediastinal emergency.
7) No tension physiology.
8) No large pneumothorax.
9) No mediastinal emergency.
10) No tension physiology.
11) No large pneumothorax.
TXT;

    $clean = MedgemmaDraft::cleanLab($raw);
    $split = MedgemmaDraft::split($clean);

    expect($split['findings'])->toContain('nodular opacities')
        ->and($split['impression'])->toContain('Recommend CT chest')
        ->and($split['impression'])->toContain('No mediastinal emergency')
        ->and($split['impression'])->toContain('No large pneumothorax')
        ->and($split['impression'])->not->toContain('9)')
        ->and(substr_count(strtolower($split['impression']), 'no mediastinal emergency'))->toBe(1);
});

test('scrubReport cuts a stored looping impression so the record page is readable', function () {
    $loop = <<<'TXT'
FINDINGS:
Lungs show nodular opacities.

IMPRESSION:
1) Nodular opacities. Recommend CT chest.
2) No mediastinal emergency.
3) No tension physiology.
4) No large pneumothorax.
5) No mediastinal emergency.
TXT;

    $report = MedgemmaDraft::scrubReport([
        'medgemma_draft' => $loop,
        'findings_narrative' => 'Lungs show nodular opacities.',
        'impression' => "1) Nodular opacities. Recommend CT chest.\n2) No mediastinal emergency.\n3) No tension physiology.\n4) No large pneumothorax.\n5) No mediastinal emergency.",
        'summary' => 'looped',
    ]);

    expect($report['impression'])->toContain('Recommend CT chest')
        ->and($report['impression'])->not->toContain('5)')
        ->and(substr_count(strtolower($report['impression']), 'no mediastinal emergency'))->toBe(1);
});

test('split strips a repeated findings heading', function () {
    $split = MedgemmaDraft::split(
        "FINDINGS:\nFINDINGS:\nESR 40 mm/hr (0-20).\n\nIMPRESSION:\nIsolated ESR elevation."
    );

    expect($split['findings'])->toBe('ESR 40 mm/hr (0-20).')
        ->and($split['findings'])->not->toStartWith('FINDINGS')
        ->and($split['impression'])->toBe('Isolated ESR elevation.');
});
