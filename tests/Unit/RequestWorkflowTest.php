<?php

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Enums\DecisionAction;
use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Jobs\SendRequestStatusNotification;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Services\RequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** The workflow service in isolation: rules, audit trail, atomicity and notification dispatch. */
class RequestWorkflowTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    private RequestWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
        $this->workflow = app(RequestWorkflow::class);
        Queue::fake();
    }

    public function test_create_saves_a_draft_owned_by_the_actor_and_logs_it(): void
    {
        $request = $this->workflow->create($this->employee, $this->validPayload());

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertSame($this->employee->id, $request->requester_id);
        $this->assertSame(['created'], $request->activities->pluck('type.value')->all());
        Queue::assertNothingPushed();
    }

    public function test_create_with_submit_writes_two_activities_and_notifies_once(): void
    {
        $request = $this->workflow->create($this->employee, $this->validPayload(), submit: true);

        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->submitted_at);
        $this->assertSame(['created', 'transitioned'], $request->activities->pluck('type.value')->all());
        Queue::assertPushed(SendRequestStatusNotification::class, 1);
    }

    public function test_priority_defaults_to_medium(): void
    {
        $request = $this->workflow->create($this->employee, ['title' => 'x', 'description' => 'y', 'category' => 'access']);

        $this->assertSame('medium', $request->fresh()->priority->value);
    }

    public function test_an_illegal_transition_changes_nothing_logs_nothing_and_notifies_nobody(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        try {
            $this->workflow->submit($request, $this->employee);
            $this->fail('Expected a WorkflowException');
        } catch (WorkflowException $e) {
            $this->assertSame('invalid_transition', $e->errorCode);
        }

        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        $this->assertSame(0, RequestActivity::count());
        Queue::assertNothingPushed();
    }

    public function test_the_status_change_and_its_activity_row_commit_or_roll_back_together(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        // Make the audit insert blow up *after* the status UPDATE has already run.
        RequestActivity::creating(fn () => throw new RuntimeException('audit store unavailable'));

        try {
            $this->workflow->decide($request, $this->manager, DecisionAction::Approve);
            $this->fail('Expected the audit failure to propagate');
        } catch (RuntimeException) {
            // expected
        } finally {
            RequestActivity::flushEventListeners();
        }

        $fresh = $request->fresh();
        $this->assertSame(RequestStatus::Submitted, $fresh->status, 'status must roll back with the failed audit write');
        $this->assertNull($fresh->due_at);
        Queue::assertNothingPushed();
    }

    public function test_a_stale_copy_cannot_overwrite_a_decision_someone_else_already_made(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $staleCopy = ServiceRequest::findOrFail($request->id); // two people opened the same request

        $this->workflow->decide($request, $this->manager, DecisionAction::Approve);

        // The second manager still sees "submitted" in their copy, but the workflow re-reads the locked row.
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("already 'approved'");
        try {
            $this->workflow->decide($staleCopy, $this->manager, DecisionAction::Approve);
        } finally {
            $this->assertSame(1, RequestActivity::where('service_request_id', $request->id)->count());
        }
    }

    public function test_approving_starts_the_sla_clock_from_the_priority(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');
        $request = $this->requestIn(RequestStatus::Submitted, attributes: ['priority' => 'urgent']);

        $approved = $this->workflow->decide($request, $this->manager, DecisionAction::Approve);

        $this->assertTrue($approved->due_at->equalTo(Carbon::parse('2026-03-02 17:00:00')), 'urgent = 8 hours');
    }

    public function test_assigning_an_approved_request_moves_it_to_assigned_and_records_the_owner(): void
    {
        $request = $this->requestIn(RequestStatus::Approved);

        $assigned = $this->workflow->assign($request, $this->ops, $this->ops2, 'Omar has the parts');

        $this->assertSame(RequestStatus::Assigned, $assigned->status);
        $this->assertSame($this->ops2->id, $assigned->assignee_id);
        $activity = $assigned->activities()->latest('id')->first();
        $this->assertSame(ActivityType::Assigned, $activity->type);
        $this->assertSame(RequestStatus::Approved, $activity->from_status);
        $this->assertSame(RequestStatus::Assigned, $activity->to_status);
        $this->assertSame('Omar has the parts', $activity->comment);
        $this->assertSame($this->ops2->id, $activity->meta['assignee_id']);
    }

    public function test_reassigning_keeps_the_status_and_remembers_the_previous_owner(): void
    {
        $request = $this->requestIn(RequestStatus::InProgress); // owner: ops

        $request = $this->workflow->assign($request, $this->ops, $this->ops2);

        $this->assertSame(RequestStatus::InProgress, $request->status);
        $this->assertSame($this->ops2->id, $request->assignee_id);
        $meta = $request->activities()->latest('id')->first()->meta;
        $this->assertSame($this->ops->id, $meta['previous_assignee_id']);
    }

    public function test_assigning_is_refused_before_approval_and_after_completion(): void
    {
        foreach ([RequestStatus::Draft, RequestStatus::Submitted, RequestStatus::Returned, RequestStatus::Rejected, RequestStatus::Completed] as $status) {
            $request = $this->requestIn($status);
            $ownerBefore = $request->assignee_id;

            try {
                $this->workflow->assign($request, $this->ops, $this->ops2);
                $this->fail("Assigning in {$status->value} should be refused");
            } catch (WorkflowException) {
                $this->assertSame($ownerBefore, $request->fresh()->assignee_id, "owner must not change in {$status->value}");
            }
        }

        $this->assertSame(0, RequestActivity::count());
    }

    public function test_work_moves_forward_only_assigned_to_in_progress_to_completed(): void
    {
        $request = $this->requestIn(RequestStatus::Assigned);

        $request = $this->workflow->changeStatus($request, $this->ops, RequestStatus::InProgress);
        $request = $this->workflow->changeStatus($request, $this->ops, RequestStatus::Completed, 'Done');

        $this->assertSame(RequestStatus::Completed, $request->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_the_status_endpoint_logic_refuses_to_be_used_for_anything_but_starting_or_completing(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->expectException(InvalidArgumentException::class);
        $this->workflow->changeStatus($request, $this->ops, RequestStatus::Approved);
    }

    public function test_update_only_works_on_draft_or_returned_and_logs_what_changed(): void
    {
        $request = $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Old title']);

        $this->workflow->update($request, $this->employee, ['title' => 'New title']);

        $activity = $request->activities()->first();
        $this->assertSame(ActivityType::Updated, $activity->type);
        $this->assertSame(['title' => ['from' => 'Old title', 'to' => 'New title']], $activity->meta['changes']);

        $submitted = $this->requestIn(RequestStatus::Submitted);
        $this->expectException(WorkflowException::class);
        $this->workflow->update($submitted, $this->employee, ['title' => 'Sneaky']);
    }

    public function test_saving_identical_values_is_not_an_audit_event(): void
    {
        $request = $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Same']);

        $this->workflow->update($request, $this->employee, ['title' => 'Same']);

        $this->assertSame(0, RequestActivity::count());
    }

    public function test_comments_are_logged_but_are_not_status_changes_and_close_with_the_request(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $comment = $this->workflow->addComment($request, $this->manager, 'Which days is this needed?');

        $activity = $request->activities()->first();
        $this->assertSame(ActivityType::Commented, $activity->type);
        $this->assertSame($comment->id, $activity->meta['comment_id']);
        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        Queue::assertNothingPushed();

        $done = $this->requestIn(RequestStatus::Completed);
        $this->expectException(WorkflowException::class);
        $this->workflow->addComment($done, $this->employee, 'Thanks!');
    }

    public function test_the_notification_job_is_dispatched_after_commit_for_each_transition_and_can_be_switched_off(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->workflow->decide($request, $this->manager, DecisionAction::Approve);

        Queue::assertPushed(SendRequestStatusNotification::class, function (SendRequestStatusNotification $job) {
            return $job->afterCommit === true && $job->activity->to_status === RequestStatus::Approved;
        });

        Queue::fake(); // reset
        config(['service_requests.notifications_enabled' => false]);
        $this->workflow->assign($request->fresh(), $this->ops, $this->ops);
        Queue::assertNothingPushed();
    }
}
