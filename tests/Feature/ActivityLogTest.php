<?php

namespace Tests\Feature;

use App\Enums\DecisionAction;
use App\Enums\RequestStatus;
use App\Services\RequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** GET /api/requests/{id}/activities: the audit trail "can be queried per request". */
class ActivityLogTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    public function test_the_trail_is_oldest_first_and_says_who_did_what_and_why(): void
    {
        $workflow = app(RequestWorkflow::class);
        $request = $workflow->create($this->employee, $this->validPayload(), submit: true);
        $workflow->decide($request, $this->manager, DecisionAction::Return, 'Add the budget code');

        $response = $this->signIn($this->employee)->getJson("/api/requests/{$request->id}/activities")->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'created')
            ->assertJsonPath('data.1.to_status', 'submitted')
            ->assertJsonPath('data.2.to_status', 'returned')
            ->assertJsonPath('data.2.from_status', 'submitted')
            ->assertJsonPath('data.2.comment', 'Add the budget code')
            ->assertJsonPath('data.2.actor.name', 'Maya Manager')
            ->assertJsonStructure(['data' => [['id', 'type', 'from_status', 'to_status', 'comment', 'meta', 'actor' => ['id', 'name', 'role'], 'created_at']]]);
    }

    public function test_the_trail_is_paginated(): void
    {
        $workflow = app(RequestWorkflow::class);
        $request = $this->requestIn(RequestStatus::Draft);
        foreach (range(1, 5) as $i) {
            $workflow->update($request, $this->employee, ['title' => "Title {$i}"]);
        }

        $this->signIn($this->employee)->getJson("/api/requests/{$request->id}/activities?per_page=2&page=3")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson("/api/requests/{$request->id}/activities?per_page=500")->assertStatus(422);
    }

    public function test_each_request_only_shows_its_own_history(): void
    {
        $workflow = app(RequestWorkflow::class);
        $mine = $workflow->create($this->employee, $this->validPayload(), submit: true);
        $workflow->create($this->employee, $this->validPayload(['title' => 'Another']), submit: true);

        $this->signIn($this->employee)->getJson("/api/requests/{$mine->id}/activities")->assertJsonCount(2, 'data');
    }

    public function test_the_manager_and_operations_can_read_the_trail_when_they_can_see_the_request(): void
    {
        $request = $this->requestIn(RequestStatus::Approved);

        $this->signIn($this->manager)->getJson("/api/requests/{$request->id}/activities")->assertOk();
        $this->signIn($this->ops)->getJson("/api/requests/{$request->id}/activities")->assertOk();
    }

    public function test_the_audit_trail_has_no_write_endpoints(): void
    {
        $request = $this->requestIn(RequestStatus::Draft);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->signIn($this->employee)->json($method, "/api/requests/{$request->id}/activities", ['type' => 'created'])
                ->assertStatus(405)->assertJson(['code' => 'method_not_allowed']);
        }
    }
}
