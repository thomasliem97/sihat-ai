<?php

namespace App\Services;

use App\Models\MedicalRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeidentificationService
{
    /**
     * Scrub PHI into a sibling safe file; leave original for physician download.
     */
    public function deidentify(MedicalRecord $record): void
    {
        $sanitizedName = preg_replace(
            [
                '/\d{6}-\d{2}-\d{4}/',
                '/\d{3}-\d{4}\s?\d{4}/',
                '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            ],
            '[REDACTED]',
            $record->original_filename,
        ) ?? $record->original_filename;

        $updates = [];
        if ($sanitizedName !== $record->original_filename) {
            $updates['original_filename'] = $sanitizedName;
        }

        $path = $record->file_path;
        if (! Storage::disk('local')->exists($path)) {
            if ($updates !== []) {
                $record->update($updates);
            }

            return;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $safeRelative = 'medical-records/safe/'.Str::uuid().($ext !== '' ? '.'.$ext : '');
        Storage::disk('local')->copy($path, $safeRelative);

        $absolute = Storage::disk('local')->path($safeRelative);
        $mime = strtolower($record->mime_type);
        $lower = strtolower($sanitizedName);

        if (str_ends_with($lower, '.dcm') || str_contains($mime, 'dicom')) {
            $this->scrubDicomTags($absolute);
        } elseif (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') || str_contains($mime, 'png')) {
            $this->stripImageExif($absolute, $mime);
        }

        $updates['safe_file_path'] = $safeRelative;
        $record->update($updates);
    }

    /**
     * Redact common PHI patterns from extracted document text.
     */
    public function scrubText(string $text): string
    {
        $patterns = [
            '/\b\d{6}-\d{2}-\d{4}\b/',
            '/\b\d{3}-\d{4}\s?\d{4}\b/',
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
            '/\bMRN[:\s#]*\d+\b/i',
            '/\b(Patient|Name|Nama)[:\s]+[A-Za-z][A-Za-z\s.\']{2,40}/i',
        ];

        $scrubbed = $text;
        foreach ($patterns as $pattern) {
            $scrubbed = preg_replace($pattern, '[REDACTED]', $scrubbed) ?? $scrubbed;
        }

        return $scrubbed;
    }

    private function scrubDicomTags(string $absolutePath): void
    {
        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return;
        }

        $phiKeywords = [
            'PatientName',
            'PatientID',
            'PatientBirthDate',
            'PatientSex',
            'PatientAddress',
            'InstitutionName',
            'ReferringPhysicianName',
            'OperatorsName',
            'OtherPatientIDs',
            'OtherPatientNames',
        ];

        $scrubbed = $bytes;
        foreach ($phiKeywords as $keyword) {
            $scrubbed = str_ireplace($keyword, str_repeat('X', strlen($keyword)), $scrubbed);
        }

        $scrubbed = preg_replace('/\d{6}-\d{2}-\d{4}/', 'XXXXXX-XX-XXXX', $scrubbed) ?? $scrubbed;

        if ($scrubbed !== $bytes) {
            file_put_contents($absolutePath, $scrubbed);
        }
    }

    private function stripImageExif(string $absolutePath, string $mime): void
    {
        if (! function_exists('imagecreatefromstring')) {
            return;
        }

        $data = @file_get_contents($absolutePath);
        if ($data === false) {
            return;
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return;
        }

        if (str_contains($mime, 'png')) {
            imagepng($image, $absolutePath);
        } else {
            imagejpeg($image, $absolutePath, 92);
        }

        imagedestroy($image);
    }
}
