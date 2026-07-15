<?php

namespace App\Enums;

enum TriageRoleContext: string
{
    case Patient = 'patient';
    case Physician = 'physician';
}
