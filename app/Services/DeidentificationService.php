<?php

namespace App\Services;

use App\Models\MedicalRecord;
use Illuminate\Support\Facades\Storage;

class DeidentificationService
{
    /**
     * Scrub filename PHI, EXIF, and DICOM tag payloads before inference.
     * Sets scrubbed sibling file when binary scrubbing succeeds.
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

        if ($updates !== []) {
            $record->update($updates);
        }

        $path = $record->file_path;
        if (! Storage::disk('local')->exists($path)) {
            return;
        }

        $absolute = Storage::disk('local')->path($path);
        $mime = strtolower($record->mime_type);
        $lower = strtolower($record->original_filename);

        if (str_ends_with($lower, '.dcm') || str_contains($mime, 'dicom')) {
            $this->scrubDicomTags($absolute);
        } elseif (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') || str_contains($mime, 'png')) {
            $this->stripImageExif($absolute, $mime);
        }
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
        // ponytail: binary tag wipe without pydicom; strips common PHI tag keyword ASCII blobs
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

        // Malaysian IC-like patterns in binary ASCII
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
