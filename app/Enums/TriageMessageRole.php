<?php

namespace App\Enums;

enum TriageMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
