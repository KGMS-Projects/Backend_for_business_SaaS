<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/**
 * Tests that invalid status transitions return 422 Unprocessable Content.
 */
class InvalidTransitionTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    /** @return iterable<string, array{string, RequestStatus, string, array<string, string>, string}> actor, status, endpoint, payload, error code */
    public static function impossibleMoves(): iterable
    {
        $approve = ['decision' => 'approve'];
        $reject = ['decision' => 'reject', 'comment' => 'No'];

        // Re-doing something that already happened (double clicks, retries).
        yield 'submit twice' => ['employee', RequestStatus::Submitted, '/submit', [], 'invalid_transition'];
        yield 'approve twice' => ['manager', RequestStatus::Approved, '/decision', $approve, 'invalid_transition'];
        yield 'start work twice' => ['ops', RequestStatus::InProgress, '/status', ['status' => 'in_progress'], 'invalid_transition'];

        // Skipping steps.
        yield 'submit an approved request' => ['employee', RequestStatus::Approved, '/submit', [], 'invalid_transition'];
        yield 'approve a returned request without resubmission' => ['manager', RequestStatus::Returned, '/decision', $approve, 'invalid_transition'];
        yield 'reject after approving' => ['manager', RequestStatus::Approved, '/decision', $reject, 'invalid_transition'];
        yield 'start work before assigning an owner' => ['ops', RequestStatus::Approved, '/status', ['status' => 'in_progress'], 'invalid_transition'];
        yield 'complete straight from approved' => ['ops', RequestStatus::Approved, '/status', ['status' => 'completed'], 'invalid_transition'];
        yield 'complete without starting' => ['ops', RequestStatus::Assigned, '/status', ['status' => 'completed'], 'invalid_transition'];
        yield 'reject a returned request' => ['manager', RequestStatus::Returned, '/decision', $reject, 'invalid_transition'];

        // Final states never change again.
        yield 'approve a rejected request' => ['manager', RequestStatus::Rejected, '/decision', $approve, 'request_final'];
        yield 'resubmit a rejected request' => ['employee', RequestStatus::Rejected, '/submit', [], 'request_final'];
        yield 'edit a rejected request' => ['employee', RequestStatus::Rejected, '', ['title' => 'x'], 'request_final'];
        yield 'reopen a completed request' => ['ops', RequestStatus::Completed, '/status', ['status' => 'in_progress'], 'request_final'];
        yield 'reassign a completed request' => ['ops', RequestStatus::Completed, '/assign', ['assignee_id' => '{ops}'], 'request_final'];
        yield 'resubmit a completed request' => ['employee', RequestStatus::Completed, '/submit', [], 'request_final'];
        yield 'comment on a completed request' => ['employee', RequestStatus::Completed, '/comments', ['body' => 'thanks'], 'request_final'];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('impossibleMoves')]
    public function test_a_move_the_lifecycle_forbids_returns_422_and_changes_nothing(string $actor, RequestStatus $status, string $endpoint, array $payload, string $code): void
    {
        Queue::fake();
        $request = $this->requestIn($status);
        $payload = array_map(fn ($v) => $v === '{ops}' ? $this->ops2->id : $v, $payload);
        $method = $endpoint === '' ? 'PUT' : 'POST';

        $this->signIn($this->{$actor})
            ->json($method, "/api/requests/{$request->id}{$endpoint}", $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'code'])
            ->assertJson(['code' => $code]);

        $fresh = $request->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertSame($request->assignee_id, $fresh->assignee_id);
        $this->assertSame(0, RequestActivity::count(), 'a refused move must leave no audit trail');
        Queue::assertNothingPushed();
    }

    public function test_the_error_message_names_the_statuses_involved(): void
    {
        $request = $this->requestIn(RequestStatus::Assigned);

        $this->signIn($this->ops)->postJson("/api/requests/{$request->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => "A request cannot move from 'assigned' to 'completed'. Allowed next status: 'in_progress'.",
                'code' => 'invalid_transition',
            ]);
    }

    public function test_a_second_identical_approval_is_idempotent_in_effect(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $this->signIn($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertOk();
        $dueAt = $request->fresh()->due_at;

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertStatus(422)->assertJsonPath('message', "This request is already 'approved'.");

        $this->assertEquals($dueAt, $request->fresh()->due_at, 'the SLA clock must not restart');
        $this->assertSame(1, $request->activities()->count());
    }
}
