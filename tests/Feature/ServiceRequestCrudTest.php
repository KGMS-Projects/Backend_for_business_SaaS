<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** The employee's side: create, edit, read. */
class ServiceRequestCrudTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    public function test_an_employee_can_save_a_draft(): void
    {
        $this->signIn($this->employee)
            ->postJson('/api/requests', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'New laptop')
            ->assertJsonPath('data.requester.id', $this->employee->id)
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.due_at', null);

        $this->assertDatabaseHas('request_activities', ['type' => 'created', 'actor_id' => $this->employee->id]);
    }

    public function test_an_employee_can_create_and_submit_in_one_call(): void
    {
        $this->signIn($this->employee)
            ->postJson('/api/requests', $this->validPayload(['submit' => true]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(2, RequestActivity::count()); // created + submitted
    }

    public function test_status_owner_and_requester_cannot_be_forced_through_the_payload(): void
    {
        $response = $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload([
            'status' => 'completed',
            'requester_id' => $this->outsider->id,
            'assignee_id' => $this->ops->id,
            'due_at' => '2030-01-01',
        ]))->assertCreated();

        $request = ServiceRequest::findOrFail($response->json('data.id'));
        $this->assertSame(RequestStatus::Draft, $request->status);
        $this->assertSame($this->employee->id, $request->requester_id);
        $this->assertNull($request->assignee_id);
        $this->assertNull($request->due_at);
    }

    public function test_priority_is_optional_and_defaults_to_medium(): void
    {
        $this->signIn($this->employee)
            ->postJson('/api/requests', ['title' => 'Badge', 'description' => 'Lost my badge', 'category' => 'access'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'medium');
    }

    public function test_validation_errors_use_the_standard_envelope(): void
    {
        $this->signIn($this->employee)
            ->postJson('/api/requests', ['title' => str_repeat('x', 151), 'category' => 'banana', 'priority' => 'asap', 'submit' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'code', 'errors'])
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['title', 'description', 'category', 'priority', 'submit']);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_an_employee_can_edit_a_draft_and_the_change_is_audited(): void
    {
        $request = $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Old', 'priority' => 'low']);

        $this->signIn($this->employee)
            ->putJson("/api/requests/{$request->id}", ['title' => 'New', 'priority' => 'urgent'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.status', 'draft');

        $activity = $request->activities()->first();
        $this->assertSame('updated', $activity->type->value);
        $this->assertSame(['from' => 'low', 'to' => 'urgent'], $activity->meta['changes']['priority']);
    }

    public function test_an_employee_can_edit_a_returned_request(): void
    {
        $request = $this->requestIn(RequestStatus::Returned);

        $this->signIn($this->employee)
            ->putJson("/api/requests/{$request->id}", ['description' => 'Now with the budget code'])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');
    }

    public function test_editing_needs_at_least_one_field(): void
    {
        $request = $this->requestIn(RequestStatus::Draft);

        $this->signIn($this->employee)->putJson("/api/requests/{$request->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_editing_is_refused_once_the_request_is_with_the_manager_or_beyond(): void
    {
        foreach ([RequestStatus::Submitted, RequestStatus::Approved, RequestStatus::Assigned, RequestStatus::InProgress] as $status) {
            $request = $this->requestIn($status);

            $this->signIn($this->employee)
                ->putJson("/api/requests/{$request->id}", ['title' => 'Changed my mind'])
                ->assertStatus(422)
                ->assertJson(['code' => 'request_not_editable'])
                ->assertJsonPath('message', "Only draft or returned requests can be edited; this request is '{$status->value}'.");

            $this->assertNotSame('Changed my mind', $request->fresh()->title);
        }
    }

    public function test_rejected_and_completed_requests_are_final(): void
    {
        foreach ([RequestStatus::Rejected, RequestStatus::Completed] as $status) {
            $request = $this->requestIn($status);

            $this->signIn($this->employee)
                ->putJson("/api/requests/{$request->id}", ['title' => 'Reopen'])
                ->assertStatus(422)
                ->assertJson(['code' => 'request_final']);
        }
    }

    public function test_show_returns_details_with_comments_and_activity_history(): void
    {
        $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['submit' => true]));
        $id = ServiceRequest::firstOrFail()->id;
        $this->signIn($this->manager)->postJson("/api/requests/{$id}/comments", ['body' => 'Which model?'])->assertCreated();

        $this->signIn($this->employee)->getJson("/api/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.requester.name', 'Eli Employee')
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.body', 'Which model?')
            ->assertJsonPath('data.comments.0.author.name', 'Maya Manager')
            ->assertJsonCount(3, 'data.activity')
            ->assertJsonPath('data.activity.0.type', 'created')
            ->assertJsonPath('data.activity.1.to_status', 'submitted')
            ->assertJsonPath('data.activity.2.type', 'commented')
            ->assertJsonPath('data.activity.0.actor.name', 'Eli Employee');
    }

    public function test_an_unknown_request_is_a_404_with_the_standard_envelope(): void
    {
        $this->signIn($this->employee)->getJson('/api/requests/9999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The requested resource was not found.', 'code' => 'not_found']);
    }
}
