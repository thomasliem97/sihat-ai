<?php

namespace App\Enums;

enum UserRole: string
{
    case Physician = 'physician';
    case Patient = 'patient';
}
