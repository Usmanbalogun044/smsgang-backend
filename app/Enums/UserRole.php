<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Vendor = 'vendor';
    case Admin = 'admin';
}
