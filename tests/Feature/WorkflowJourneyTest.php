<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** Happy paths: each role doing its part, end to end over HTTP. */
class WorkflowJourneyTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    /** @return list<string> "type:from>to:actor" for every audit row of the request */
    private function history(int $id): array
    {
        return ServiceRequest::findOrFail($id)->activities()->with('actor')->get()
            ->map(fn ($a) => sprintf('%s:%s>%s:%s', $a->type->value, $a->from_status?->value, $a->to_status?->value, $a->actor->name))
            ->all();
    }

    public function test_the_whole_journey_from_creation_to_completion(): void
    {
        // 1. Employee creates a draft, then sends it for review.
        $id = $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['priority' => 'urgent']))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.is_final', false);

        // 2. Manager approves.
        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'approve', 'comment' => 'Go ahead'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_overdue', false);
        $this->assertNotNull(ServiceRequest::find($id)->due_at, 'approval starts the SLA clock');

        // 3. Operations assigns an owner, starts the work and completes it.
        $this->signIn($this->ops)->postJson("/api/requests/{$id}/assign", ['assignee_id' => $this->ops2->id, 'comment' => 'Omar will take it'])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assignee.id', $this->ops2->id);

        $this->signIn($this->ops2)->postJson("/api/requests/{$id}/status", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->postJson("/api/requests/{$id}/status", ['status' => 'completed', 'comment' => 'Laptop delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_final', true)
            ->assertJsonPath('data.is_overdue', false);
        $this->assertNotNull(ServiceRequest::find($id)->completed_at);

        // 4. The audit trail tells the whole story, in order, with who did each step.
        $this->assertSame([
            'created:>draft:Eli Employee',
            'transitioned:draft>submitted:Eli Employee',
            'transitioned:submitted>approved:Maya Manager',
            'assigned:approved>assigned:Olivia Ops',
            'transitioned:assigned>in_progress:Omar Ops',
            'transitioned:in_progress>completed:Omar Ops',
        ], $this->history($id));

        // 5. Everybody involved sees the finished request.
        $this->signIn($this->employee)->getJson("/api/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->signIn($this->manager)->getJson("/api/requests/{$id}")->assertOk();
    }

    public function test_transition_comments_are_recorded_with_who_and_when(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'return', 'comment' => 'Add the budget code'])->assertOk();

        $activity = $request->activities()->first();
        $this->assertSame('Add the budget code', $activity->comment);
        $this->assertSame($this->manager->id, $activity->actor_id);
        $this->assertNotNull($activity->created_at);
    }

    public function test_a_returned_request_goes_back_to_the_employee_who_fixes_and_resubmits_it(): void
    {
        $id = $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['submit' => true]))->json('data.id');

        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'return', 'comment' => 'Which model do you need?'])
            ->assertOk()->assertJsonPath('data.status', 'returned');

        $this->signIn($this->employee)->putJson("/api/requests/{$id}", ['description' => 'A 14-inch ThinkPad, budget code 4471'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $this->postJson("/api/requests/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame([
            'created:>draft:Eli Employee',
            'transitioned:draft>submitted:Eli Employee',
            'transitioned:submitted>returned:Maya Manager',
            'updated:returned>returned:Eli Employee',
            'transitioned:returned>submitted:Eli Employee',
            'transitioned:submitted>approved:Maya Manager',
        ], $this->history($id));
    }

    public function test_the_manager_can_reject_and_a_rejected_request_is_dead(): void
    {
        $id = $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['submit' => true]))->json('data.id');

        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'reject', 'comment' => 'Out of budget'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.is_final', true);

        $this->signIn($this->employee);
        $this->putJson("/api/requests/{$id}", ['title' => 'Try again'])->assertStatus(422)->assertJson(['code' => 'request_final']);
        $this->postJson("/api/requests/{$id}/submit")->assertStatus(422)->assertJson(['code' => 'request_final']);
        $this->postJson("/api/requests/{$id}/comments", ['body' => 'Please reconsider'])->assertStatus(422)->assertJson(['code' => 'request_final']);

        $this->signIn($this->manager)->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])
            ->assertStatus(422)->assertJson(['code' => 'request_final']);

        $this->assertSame(RequestStatus::Rejected, ServiceRequest::find($id)->status);
    }

    public function test_a_completed_request_is_final_for_every_role(): void
    {
        $request = $this->requestIn(RequestStatus::Completed);
        $url = "/api/requests/{$request->id}";

        $this->signIn($this->employee);
        $this->putJson($url, ['title' => 'Edit'])->assertStatus(422)->assertJson(['code' => 'request_final']);
        $this->postJson("{$url}/submit")->assertStatus(422)->assertJson(['code' => 'request_final']);

        $this->signIn($this->manager)->postJson("{$url}/decision", ['decision' => 'reject', 'comment' => 'Too late'])
            ->assertStatus(422)->assertJson(['code' => 'request_final']);

        $this->signIn($this->ops);
        $this->postJson("{$url}/assign", ['assignee_id' => $this->ops2->id])->assertStatus(422)->assertJson(['code' => 'request_final']);
        $this->postJson("{$url}/status", ['status' => 'in_progress'])->assertStatus(422)->assertJson(['code' => 'request_final']);

        $this->assertSame(0, $request->activities()->count(), 'nothing may be written to a final request');
    }

    public function test_operations_can_hand_work_to_a_colleague_while_it_is_in_progress(): void
    {
        $request = $this->requestIn(RequestStatus::InProgress); // owned by ops

        $this->signIn($this->ops)->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $this->ops2->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assignee.id', $this->ops2->id);
    }

    public function test_decision_comment_rules(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $url = "/api/requests/{$request->id}/decision";
        $this->signIn($this->manager);

        $this->postJson($url, ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors(['comment']);
        $this->postJson($url, ['decision' => 'return', 'comment' => ''])->assertStatus(422)->assertJsonValidationErrors(['comment']);
        $this->postJson($url, ['decision' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['decision']);
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['decision']);

        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        $this->postJson($url, ['decision' => 'approve'])->assertOk(); // approving needs no comment
    }

    public function test_the_owner_must_be_an_operations_user(): void
    {
        $request = $this->requestIn(RequestStatus::Approved);
        $this->signIn($this->ops);

        foreach ([$this->employee->id, $this->manager->id, 99999] as $badId) {
            $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $badId])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['assignee_id']);
        }
        $this->postJson("/api/requests/{$request->id}/assign", [])->assertStatus(422)->assertJsonValidationErrors(['assignee_id']);

        $this->assertNull($request->fresh()->assignee_id);
    }

    public function test_the_status_endpoint_only_accepts_in_progress_or_completed(): void
    {
        $request = $this->requestIn(RequestStatus::Assigned);
        $this->signIn($this->ops);

        foreach (['approved', 'draft', 'rejected', 'banana', ''] as $value) {
            $this->postJson("/api/requests/{$request->id}/status", ['status' => $value])
                ->assertStatus(422)->assertJsonValidationErrors(['status']);
        }
        $this->assertSame(RequestStatus::Assigned, $request->fresh()->status);
    }
}
