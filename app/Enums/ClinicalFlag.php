<?php

namespace App\Enums;

enum ClinicalFlag: string
{
    case Normal = 'normal';
    case Borderline = 'borderline';
    case Abnormal = 'abnormal';
    case Critical = 'critical';
}
