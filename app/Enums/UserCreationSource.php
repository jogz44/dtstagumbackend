<?php

namespace App\Enums;

enum UserCreationSource: string
{
    case PMIS = 'PMIS';
    case MANUAL = 'MANUAL';
}
