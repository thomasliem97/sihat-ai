<?php

namespace App\Enums;

enum Modality: string
{
    case Xray = 'xray';
    case Ct = 'ct';
    case Mri = 'mri';
    case Histopath = 'histopath';
    case Dermatology = 'dermatology';
    case Ophthalmology = 'ophthalmology';
    case LabPdf = 'lab_pdf';
    case ClinicalDocument = 'clinical_document';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Xray => 'Chest X-ray',
            self::Ct => 'CT Scan',
            self::Mri => 'MRI',
            self::Histopath => 'Histopathology',
            self::Dermatology => 'Dermatology',
            self::Ophthalmology => 'Ophthalmology',
            self::LabPdf => 'Lab Report (PDF)',
            self::ClinicalDocument => 'Clinical Document (PDF)',
            self::Unknown => 'Unknown',
        };
    }

    public function isDocument(): bool
    {
        return $this === self::LabPdf || $this === self::ClinicalDocument;
    }

    public function isImaging(): bool
    {
        return match ($this) {
            self::Xray, self::Ct, self::Mri, self::Histopath, self::Dermatology, self::Ophthalmology => true,
            default => false,
        };
    }
}
