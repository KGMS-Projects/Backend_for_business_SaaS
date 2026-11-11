<?php

namespace App\Enums;

/** What a manager can do when reviewing a submitted request. */
enum DecisionAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';

    public function targetStatus(): RequestStatus
    {
        return match ($this) {
            self::Approve => RequestStatus::Approved,
            self::Reject => RequestStatus::Rejected,
            self::Return => RequestStatus::Returned,
        };
    }

    /** Rejecting or returning a request without saying why is not helpful to anyone. */
    public function requiresComment(): bool
    {
        return $this !== self::Approve;
    }
}
