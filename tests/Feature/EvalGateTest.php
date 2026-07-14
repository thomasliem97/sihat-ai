<?php

use Illuminate\Support\Facades\Artisan;

test('sihat eval gate command passes toy floors', function () {
    $exit = Artisan::call('sihat:eval-gate', [
        '--medqa-min' => 0,
        '--safety-min' => 0,
    ]);

    expect($exit)->toBe(0);
});
