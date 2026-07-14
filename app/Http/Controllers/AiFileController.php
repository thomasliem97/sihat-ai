<?php

namespace App\Http\Controllers;

use App\Models\MedicalRecord;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiFileController extends Controller
{
    public function __invoke(MedicalRecord $record): StreamedResponse
    {
        $path = $record->inferenceFilePath();

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $path,
            $record->original_filename,
        );
    }
}
