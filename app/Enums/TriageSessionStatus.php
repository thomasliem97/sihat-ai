<?php

namespace App\Enums;

enum TriageSessionStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
