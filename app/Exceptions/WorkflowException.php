<?php

namespace App\Exceptions;

use App\Enums\RequestStatus;
use DomainException;

/**
 * Thrown when an action is not allowed *in the request's current state*
 * (e.g. approving something already approved). Rendered as HTTP 422.
 *
 * "You are not allowed to do this" (wrong role / wrong team) is a different question:
 * that is a 403 from ServiceRequestPolicy and never reaches the workflow.
 */
final class WorkflowException extends DomainException
{
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidTransition(RequestStatus $from, RequestStatus $to): self
    {
        if ($from->isFinal()) {
            return self::final($from);
        }

        // Repeating an action that already happened (e.g. a double-clicked "approve").
        if ($from === $to) {
            return new self("This request is already '{$from->value}'.", 'invalid_transition');
        }

        $allowed = implode(', ', array_map(fn (RequestStatus $s) => "'{$s->value}'", $from->allowedTransitions()));

        return new self(
            "A request cannot move from '{$from->value}' to '{$to->value}'. Allowed next status: {$allowed}.",
            'invalid_transition',
        );
    }

    public static function notEditable(RequestStatus $status): self
    {
        if ($status->isFinal()) {
            return self::final($status);
        }

        return new self(
            "Only draft or returned requests can be edited; this request is '{$status->value}'.",
            'request_not_editable',
        );
    }

    public static function cannotAssign(RequestStatus $status): self
    {
        if ($status->isFinal()) {
            return self::final($status);
        }

        return new self(
            "An owner can only be assigned to approved, assigned or in-progress requests; this request is '{$status->value}'.",
            'invalid_transition',
        );
    }

    public static function commentsClosed(RequestStatus $status): self
    {
        return self::final($status);
    }

    private static function final(RequestStatus $status): self
    {
        return new self(
            "This request is '{$status->value}', which is final. It can no longer be changed.",
            'request_final',
        );
    }
}
