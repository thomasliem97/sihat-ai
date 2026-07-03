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
            self::Unknown => 'Unknown',
        };
    }
}
