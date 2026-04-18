<?php

namespace App\Enums;

enum Roles: string
{
    case Admin = 'admin';
    case Musician = 'musician';
    case Projectionist = 'projectionist';
}
