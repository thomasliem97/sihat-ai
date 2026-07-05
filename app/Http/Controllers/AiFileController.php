<?php

namespace App\Http\Controllers;

use App\Models\MedicalRecord;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiFileController extends Controller
{
    public function __invoke(MedicalRecord $record): StreamedResponse
    {
        if (! Storage::disk('local')->exists($record->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $record->file_path,
            $record->original_filename,
        );
    }
}
