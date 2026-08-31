<?php

test('public docs copies match the source documents', function (string $path) {
    $source = base_path('docs/'.$path);
    $hosted = public_path('docs/'.$path);

    expect($source)->toBeFile()
        ->and($hosted)->toBeFile()
        ->and(file_get_contents($hosted))->toBe(file_get_contents($source));
})->with([
    'project-summary.html',
    'project-summary.txt',
    'ai-disclosure.html',
    'ai-disclosure.txt',
    'pitch-deck/SihatAI-Pitch-Deck.html',
    'pitch-deck/SihatAI-Pitch-Deck.pdf',
    'pitch-deck/images/chest-xray.png',
    'pitch-deck/images/specimen-xray.png',
    'technical-architecture/SihatAI-Technical-Architecture.html',
    'technical-architecture/SihatAI-Technical-Architecture.pdf',
    'demo.mp4',
]);
