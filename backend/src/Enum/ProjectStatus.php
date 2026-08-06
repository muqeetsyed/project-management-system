<?php

namespace App\Enum;

enum ProjectStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case OnHold = 'on_hold';
}
