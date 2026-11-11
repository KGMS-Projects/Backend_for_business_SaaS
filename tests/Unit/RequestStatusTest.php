<?php

namespace Tests\Unit;

use App\Enums\DecisionAction;
use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure unit tests of the lifecycle rules: no framework, no database. */
class RequestStatusTest extends TestCase
{
    /** The whole lifecycle written out by hand, independently of the enum's own implementation. */
    private const EXPECTED = [
        'draft' => ['submitted'],
        'submitted' => ['approved', 'rejected', 'returned'],
        'returned' => ['submitted'],
        'approved' => ['assigned'],
        'assigned' => ['in_progress'],
        'in_progress' => ['completed'],
        'rejected' => [],
        'completed' => [],
    ];

    /** @return iterable<string, array{RequestStatus, RequestStatus}> */
    public static function everyPairOfStatuses(): iterable
    {
        foreach (RequestStatus::cases() as $from) {
            foreach (RequestStatus::cases() as $to) {
                yield "{$from->value} -> {$to->value}" => [$from, $to];
            }
        }
    }

    #[DataProvider('everyPairOfStatuses')]
    public function test_the_transition_matrix_matches_the_specification(RequestStatus $from, RequestStatus $to): void
    {
        $expected = in_array($to->value, self::EXPECTED[$from->value], true);

        $this->assertSame($expected, $from->canTransitionTo($to), "{$from->value} -> {$to->value}");
    }

    public function test_only_rejected_and_completed_are_final(): void
    {
        $final = array_map(fn (RequestStatus $s) => $s->value, RequestStatus::finalStatuses());

        $this->assertEqualsCanonicalizing(['rejected', 'completed'], $final);
    }

    public function test_only_draft_and_returned_are_editable(): void
    {
        $editable = array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isEditable());

        $this->assertEqualsCanonicalizing(
            ['draft', 'returned'],
            array_map(fn (RequestStatus $s) => $s->value, $editable),
        );
    }

    public function test_operations_cannot_see_requests_that_are_still_in_review_or_were_rejected(): void
    {
        $visible = array_map(fn (RequestStatus $s) => $s->value, RequestStatus::visibleToOperations());

        $this->assertEqualsCanonicalizing(['approved', 'assigned', 'in_progress', 'completed'], $visible);
    }

    public function test_every_status_can_reach_a_final_status_so_no_request_gets_stuck(): void
    {
        foreach (RequestStatus::cases() as $start) {
            $seen = [];
            $queue = [$start];
            $reachesFinal = $start->isFinal();

            while ($queue && ! $reachesFinal) {
                $current = array_pop($queue);
                if (isset($seen[$current->value])) {
                    continue;
                }
                $seen[$current->value] = true;
                foreach ($current->allowedTransitions() as $next) {
                    $reachesFinal = $reachesFinal || $next->isFinal();
                    $queue[] = $next;
                }
            }

            $this->assertTrue($reachesFinal, "{$start->value} can never finish");
        }
    }

    public function test_assignment_is_accepted_from_approved_until_completion(): void
    {
        $accepting = array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->acceptsAssignment());

        $this->assertEqualsCanonicalizing(
            ['approved', 'assigned', 'in_progress'],
            array_map(fn (RequestStatus $s) => $s->value, $accepting),
        );
    }

    public function test_decisions_map_to_the_right_status_and_rejecting_or_returning_needs_a_reason(): void
    {
        $this->assertSame(RequestStatus::Approved, DecisionAction::Approve->targetStatus());
        $this->assertSame(RequestStatus::Rejected, DecisionAction::Reject->targetStatus());
        $this->assertSame(RequestStatus::Returned, DecisionAction::Return->targetStatus());

        $this->assertFalse(DecisionAction::Approve->requiresComment());
        $this->assertTrue(DecisionAction::Reject->requiresComment());
        $this->assertTrue(DecisionAction::Return->requiresComment());
    }

    public function test_an_invalid_transition_explains_what_is_allowed(): void
    {
        $e = WorkflowException::invalidTransition(RequestStatus::Approved, RequestStatus::Completed);

        $this->assertSame('invalid_transition', $e->errorCode);
        $this->assertStringContainsString("'approved'", $e->getMessage());
        $this->assertStringContainsString("'completed'", $e->getMessage());
        $this->assertStringContainsString("'assigned'", $e->getMessage(), 'should name the allowed next status');
    }

    public function test_repeating_a_transition_says_so_instead_of_a_confusing_self_move(): void
    {
        $e = WorkflowException::invalidTransition(RequestStatus::Approved, RequestStatus::Approved);

        $this->assertSame("This request is already 'approved'.", $e->getMessage());
    }

    public function test_anything_attempted_on_a_final_request_reports_that_it_is_final(): void
    {
        foreach ([RequestStatus::Rejected, RequestStatus::Completed] as $final) {
            $this->assertSame('request_final', WorkflowException::invalidTransition($final, RequestStatus::Submitted)->errorCode);
            $this->assertSame('request_final', WorkflowException::notEditable($final)->errorCode);
            $this->assertSame('request_final', WorkflowException::cannotAssign($final)->errorCode);
            $this->assertSame('request_final', WorkflowException::commentsClosed($final)->errorCode);
        }
    }
}
