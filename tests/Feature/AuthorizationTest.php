<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/**
 * Tests that unauthorized actions return 403 Forbidden.
 */
class AuthorizationTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    /** @return iterable<string, array{string, RequestStatus, string, string}> actor, status, method, path suffix */
    public static function forbiddenActions(): iterable
    {
        $d = RequestStatus::Draft;
        $s = RequestStatus::Submitted;
        $a = RequestStatus::Approved;

        // --- reading -------------------------------------------------------
        yield 'teammate reads a colleague\'s request' => ['teammate', $s, 'GET', ''];
        yield 'employee on another team reads it' => ['outsider', $s, 'GET', ''];
        yield 'manager of another team reads it' => ['otherManager', $s, 'GET', ''];
        yield 'own manager cannot read a private draft' => ['manager', $d, 'GET', ''];
        yield 'operations cannot read a request still in review' => ['ops', $s, 'GET', ''];
        yield 'operations cannot read a rejected request' => ['ops', RequestStatus::Rejected, 'GET', ''];
        yield 'operations cannot read a returned request' => ['ops', RequestStatus::Returned, 'GET', ''];
        yield 'outsider cannot read the audit trail' => ['outsider', $a, 'GET', '/activities'];
        yield 'other manager cannot read the audit trail' => ['otherManager', $s, 'GET', '/activities'];

        // --- editing / submitting (requester only) -------------------------
        yield 'teammate edits' => ['teammate', $d, 'PUT', ''];
        yield 'own manager edits' => ['manager', RequestStatus::Returned, 'PUT', ''];
        yield 'operations edits' => ['ops', $a, 'PUT', ''];
        yield 'teammate submits' => ['teammate', $d, 'POST', '/submit'];
        yield 'manager submits' => ['manager', $d, 'POST', '/submit'];
        yield 'operations submits' => ['ops', $a, 'POST', '/submit'];

        // --- manager decisions ---------------------------------------------
        yield 'employee approves their own request' => ['employee', $s, 'POST', '/decision'];
        yield 'teammate decides' => ['teammate', $s, 'POST', '/decision'];
        yield 'manager of another team decides' => ['otherManager', $s, 'POST', '/decision'];
        yield 'operations decides' => ['ops', $s, 'POST', '/decision'];
        yield 'manager cannot decide on a draft' => ['manager', $d, 'POST', '/decision'];

        // --- operations work -------------------------------------------------
        yield 'employee assigns' => ['employee', $a, 'POST', '/assign'];
        yield 'manager assigns' => ['manager', $a, 'POST', '/assign'];
        yield 'other manager assigns' => ['otherManager', $a, 'POST', '/assign'];
        yield 'operations cannot assign a request still in review' => ['ops', $s, 'POST', '/assign'];
        yield 'employee starts work' => ['employee', RequestStatus::Assigned, 'POST', '/status'];
        yield 'manager completes work' => ['manager', RequestStatus::InProgress, 'POST', '/status'];
        yield 'operations cannot change status of a request in review' => ['ops', $s, 'POST', '/status'];

        // --- comments (participants only) ----------------------------------
        yield 'outsider comments' => ['outsider', $s, 'POST', '/comments'];
        yield 'teammate comments' => ['teammate', $s, 'POST', '/comments'];
        yield 'other manager comments' => ['otherManager', $s, 'POST', '/comments'];
        yield 'operations cannot comment on a request in review' => ['ops', $s, 'POST', '/comments'];
        yield 'manager cannot comment on a private draft' => ['manager', $d, 'POST', '/comments'];
    }

    #[DataProvider('forbiddenActions')]
    public function test_forbidden_actions_return_403_and_change_nothing(string $actor, RequestStatus $status, string $method, string $suffix): void
    {
        Queue::fake();
        $request = $this->requestIn($status);

        $response = $this->signIn($this->{$actor})->json($method, "/api/requests/{$request->id}{$suffix}", []);

        $response->assertStatus(403)->assertJsonStructure(['message', 'code'])->assertJson(['code' => 'forbidden']);

        $this->assertSame($status, $request->fresh()->status);
        $this->assertSame(0, RequestActivity::count(), 'a refused action must leave no audit trail');
        Queue::assertNothingPushed();
    }

    public function test_only_employees_can_create_requests(): void
    {
        foreach ([$this->manager, $this->ops] as $user) {
            $this->signIn($user)->postJson('/api/requests', $this->validPayload())
                ->assertStatus(403)
                ->assertJson(['code' => 'forbidden', 'message' => 'Only employees can create requests.']);
        }

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_the_refusal_explains_itself(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $this->signIn($this->otherManager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertStatus(403)
            ->assertJsonPath('message', "Only the requester's manager can review this request.");
    }

    public function test_a_manager_cannot_act_on_a_request_from_someone_who_is_not_their_direct_report(): void
    {
        $theirs = $this->requestIn(RequestStatus::Submitted, $this->outsider);

        // Even a perfectly valid decision is refused.
        $this->signIn($this->manager)->postJson("/api/requests/{$theirs->id}/decision", ['decision' => 'approve'])->assertStatus(403);
        // …while the rightful manager may act.
        $this->signIn($this->otherManager)->postJson("/api/requests/{$theirs->id}/decision", ['decision' => 'approve'])->assertOk();
    }

    public function test_a_request_without_a_manager_cannot_be_decided_by_just_any_manager(): void
    {
        $loner = User::factory()->employee()->create(); // no manager_id
        $request = $this->requestIn(RequestStatus::Submitted, $loner);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertStatus(403);
        $this->signIn($this->otherManager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertStatus(403);
    }

    public function test_authorisation_is_checked_before_the_payload_is_validated(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        // An employee sending garbage to a manager-only endpoint learns nothing about its validation rules.
        $this->signIn($this->employee)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'banana'])
            ->assertStatus(403);
    }

    public function test_a_missing_request_is_a_404_even_for_people_who_would_be_forbidden(): void
    {
        $this->signIn($this->outsider)->postJson('/api/requests/9999/decision', ['decision' => 'approve'])->assertNotFound();
    }
}
