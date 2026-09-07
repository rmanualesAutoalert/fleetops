<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case ServiceAdvisor = 'service_advisor';
}
