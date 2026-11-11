<?php

namespace App\Enums;

/**
 * The request lifecycle.
 *
 *   draft ──submit──▶ submitted ──▶ approved ──▶ assigned ──▶ in_progress ──▶ completed
 *     ▲                  │  │
 *     │                  │  └──▶ rejected   (final)
 *     └──── returned ◀───┘
 *           (employee edits, then submits again)
 *
 * This enum is the single source of truth for which moves are legal. Everything else
 * (policies, the workflow service, the API) asks it instead of re-implementing the rules.
 * *Who* may trigger a move is a separate question and lives in ServiceRequestPolicy.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::Returned],
            self::Returned => [self::Submitted],
            self::Approved => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::Completed],
            self::Rejected, self::Completed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Rejected and Completed requests can never change again. */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Employees may edit their request only in these statuses. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /** Operations may (re)assign an owner in these statuses. */
    public function acceptsAssignment(): bool
    {
        return in_array($this, [self::Approved, self::Assigned, self::InProgress], true);
    }

    /**
     * The statuses the operations team can see: everything from "approved" onwards
     * except requests a manager rejected.
     *
     * @return list<self>
     */
    public static function visibleToOperations(): array
    {
        return [self::Approved, self::Assigned, self::InProgress, self::Completed];
    }

    /**
     * @return list<self>
     */
    public static function finalStatuses(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isFinal()));
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }
}
