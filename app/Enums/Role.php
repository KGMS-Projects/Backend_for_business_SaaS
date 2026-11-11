<?php

namespace App\Enums;

enum Role: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case Operations = 'operations';
}
