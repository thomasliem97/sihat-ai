<?php

namespace App\Enums;

enum TriageUrgency: string
{
    case Routine = 'routine';
    case Urgent = 'urgent';
    case Emergency = 'emergency';
}
