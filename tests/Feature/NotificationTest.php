<?php

namespace Tests\Feature;

use App\Enums\DecisionAction;
use App\Enums\RequestStatus;
use App\Jobs\SendRequestStatusNotification;
use App\Models\RequestActivity;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use App\Services\RequestRecipients;
use App\Services\RequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    /** @return list<string> */
    private function pushedStatuses(): array
    {
        return Queue::pushed(SendRequestStatusNotification::class)
            ->map(fn (SendRequestStatusNotification $job) => $job->activity->to_status->value)->all();
    }

    // ---- dispatch ---------------------------------------------------------------

    public function test_every_status_change_over_the_api_dispatches_exactly_one_notification_job(): void
    {
        Queue::fake();

        $id = $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['submit' => true]))->json('data.id');
        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'approve']);
        $this->signIn($this->ops)->postJson("/api/requests/{$id}/assign", ['assignee_id' => $this->ops2->id]);
        $this->signIn($this->ops2)->postJson("/api/requests/{$id}/status", ['status' => 'in_progress']);
        $this->postJson("/api/requests/{$id}/status", ['status' => 'completed']);

        Queue::assertPushed(SendRequestStatusNotification::class, 5);
        $this->assertSame(['submitted', 'approved', 'assigned', 'in_progress', 'completed'], $this->pushedStatuses());
    }

    public function test_the_job_is_tied_to_the_audit_row_and_waits_for_the_commit(): void
    {
        Queue::fake();
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'return', 'comment' => 'More detail please']);

        Queue::assertPushed(SendRequestStatusNotification::class, function (SendRequestStatusNotification $job) use ($request) {
            return $job->afterCommit === true
                && $job->activity->service_request_id === $request->id
                && $job->activity->to_status === RequestStatus::Returned
                && $job->activity->comment === 'More detail please';
        });
    }

    public function test_things_that_are_not_status_changes_do_not_notify(): void
    {
        Queue::fake();
        $draft = $this->requestIn(RequestStatus::Draft);
        $submitted = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->employee)->putJson("/api/requests/{$draft->id}", ['title' => 'Edited'])->assertOk();
        $this->postJson("/api/requests/{$submitted->id}/comments", ['body' => 'Any update?'])->assertCreated();

        Queue::assertNothingPushed();
    }

    public function test_failed_or_forbidden_actions_do_not_notify(): void
    {
        Queue::fake();
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->employee)->postJson("/api/requests/{$request->id}/submit")->assertStatus(422);
        $this->signIn($this->otherManager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertStatus(403);

        Queue::assertNothingPushed();
    }

    // ---- who gets told ----------------------------------------------------------

    /**
     * Runs the workflow action, then runs the dispatched job against a faked notifier.
     *
     * @return list<string> names of the people notified
     */
    private function notifiedBy(callable $action): array
    {
        Queue::fake();
        $action(app(RequestWorkflow::class));

        /** @var RequestActivity $activity */
        $activity = Queue::pushed(SendRequestStatusNotification::class)->last()->activity;

        Notification::fake();
        (new SendRequestStatusNotification($activity))->handle(app(RequestRecipients::class));

        return User::query()->get()
            ->filter(fn (User $user) => $this->wasNotified($user))
            ->pluck('name')->sort()->values()->all();
    }

    private function wasNotified(User $user): bool
    {
        return Notification::sent($user, RequestStatusChanged::class)->isNotEmpty();
    }

    public function test_submitting_tells_the_requesters_manager(): void
    {
        $request = $this->requestIn(RequestStatus::Draft);

        $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->submit($request, $this->employee));

        $this->assertSame(['Maya Manager'], $names);
    }

    public function test_approving_tells_the_requester_and_every_operations_user_but_not_the_approver(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->decide($request, $this->manager, DecisionAction::Approve));

        $this->assertSame(['Eli Employee', 'Olivia Ops', 'Omar Ops'], $names);
    }

    public function test_rejecting_and_returning_tell_only_the_requester(): void
    {
        foreach ([DecisionAction::Reject, DecisionAction::Return] as $action) {
            $request = $this->requestIn(RequestStatus::Submitted);

            $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->decide($request, $this->manager, $action, 'Because'));

            $this->assertSame(['Eli Employee'], $names, $action->value);
        }
    }

    public function test_assigning_tells_the_new_owner_and_the_requester(): void
    {
        $request = $this->requestIn(RequestStatus::Approved);

        $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->assign($request, $this->ops, $this->ops2));

        $this->assertSame(['Eli Employee', 'Omar Ops'], $names);
    }

    public function test_assigning_to_yourself_does_not_notify_yourself(): void
    {
        $request = $this->requestIn(RequestStatus::Approved);

        $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->assign($request, $this->ops, $this->ops));

        $this->assertSame(['Eli Employee'], $names);
    }

    public function test_starting_work_tells_the_requester_and_completing_also_tells_their_manager(): void
    {
        $request = $this->requestIn(RequestStatus::Assigned);

        $this->assertSame(['Eli Employee'], $this->notifiedBy(fn (RequestWorkflow $w) => $w->changeStatus($request, $this->ops, RequestStatus::InProgress)));
        $this->assertSame(['Eli Employee', 'Maya Manager'], $this->notifiedBy(fn (RequestWorkflow $w) => $w->changeStatus($request->fresh(), $this->ops, RequestStatus::Completed)));
    }

    public function test_a_requester_without_a_manager_does_not_break_notifications(): void
    {
        $loner = User::factory()->employee()->create();
        $request = $this->requestIn(RequestStatus::Draft, $loner);

        $names = $this->notifiedBy(fn (RequestWorkflow $w) => $w->submit($request, $loner));

        $this->assertSame([], $names);
    }

    // ---- the real channels (no fakes) ------------------------------------------

    public function test_database_and_mail_notifications_are_really_written(): void
    {
        // The test queue driver is "sync", so the job runs inline, with the real notification channels.
        $request = $this->requestIn(RequestStatus::Submitted, attributes: ['title' => 'Docking station']);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertOk();

        foreach ([$this->employee, $this->ops, $this->ops2] as $user) {
            $this->assertCount(1, $user->notifications, "{$user->name} should have an in-app notification");
        }
        $this->assertCount(0, $this->manager->notifications);

        $data = $this->employee->notifications->first()->data;
        $this->assertSame($request->id, $data['request_id']);
        $this->assertSame('approved', $data['to_status']);
        $this->assertSame('Maya Manager approved "Docking station".', $data['message']);

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        $this->assertSame("[Request #{$request->id}] Approved", $messages->first()->getOriginalMessage()->getSubject());
    }

    public function test_the_mail_includes_the_managers_comment(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'return', 'comment' => 'Please add the budget code']);

        $mail = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('Please add the budget code', $mail->getTextBody());
    }

    public function test_notifications_can_be_switched_off_globally(): void
    {
        config(['service_requests.notifications_enabled' => false]);
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertOk();

        $this->assertSame(0, $this->employee->notifications()->count());
    }

    // ---- reading them -------------------------------------------------------------

    public function test_a_user_can_list_their_notifications_and_mark_them_read(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve']);

        $this->signIn($this->employee)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_id', $request->id)
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread', 1);

        $this->postJson('/api/notifications/read')->assertNoContent();

        $this->getJson('/api/notifications')->assertJsonPath('meta.unread', 0);
    }

    public function test_you_only_see_your_own_notifications(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve']);

        $this->signIn($this->teammate)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
    }
}
