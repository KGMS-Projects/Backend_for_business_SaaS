<?php

namespace App\Enums;

enum RequestPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /** Hours the operations team has to finish a request once it is approved (see config/service_requests.php). */
    public function slaHours(): int
    {
        return (int) config('service_requests.sla_hours.'.$this->value);
    }
}
