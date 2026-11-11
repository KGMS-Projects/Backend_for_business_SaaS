<?php

namespace Tests\Concerns;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A small, readable org chart shared by the tests:
 *
 *   manager ─┬─ employee        otherManager ── outsider        ops, ops2
 *            └─ teammate
 */
trait BuildsOrganisation
{
    protected User $manager;

    protected User $employee;

    protected User $teammate;

    protected User $otherManager;

    protected User $outsider;

    protected User $ops;

    protected User $ops2;

    protected function buildOrganisation(): void
    {
        $this->manager = User::factory()->manager()->create(['name' => 'Maya Manager']);
        $this->employee = User::factory()->employee($this->manager)->create(['name' => 'Eli Employee']);
        $this->teammate = User::factory()->employee($this->manager)->create(['name' => 'Tess Teammate']);

        $this->otherManager = User::factory()->manager()->create(['name' => 'Marcus Manager']);
        $this->outsider = User::factory()->employee($this->otherManager)->create(['name' => 'Olga Outsider']);

        $this->ops = User::factory()->operations()->create(['name' => 'Olivia Ops']);
        $this->ops2 = User::factory()->operations()->create(['name' => 'Omar Ops']);
    }

    /** Authenticate subsequent API calls as this user (token auth via Sanctum). */
    protected function signIn(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Insert a request directly in the given status ("snapshot"), with the columns a real
     * journey to that status would have filled in. Use the API for tests about the journey itself.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function requestIn(RequestStatus $status, ?User $requester = null, array $attributes = []): ServiceRequest
    {
        $needsOwner = in_array($status, [RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Completed], true);
        $approvedOrLater = in_array($status, RequestStatus::visibleToOperations(), true);

        return ServiceRequest::factory()
            ->requestedBy($requester ?? $this->employee)
            ->state(array_filter([
                'status' => $status,
                'assignee_id' => $needsOwner ? $this->ops->id : null,
                'submitted_at' => $status === RequestStatus::Draft ? null : now()->subDays(2),
                'due_at' => $approvedOrLater ? now()->addDay() : null,
                'completed_at' => $status === RequestStatus::Completed ? now() : null,
            ]))
            ->create($attributes);
    }

    /** @return array<string, string> a valid payload for creating a request */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'New laptop',
            'description' => 'My current laptop is five years old and very slow.',
            'category' => 'equipment',
            'priority' => 'high',
        ], $overrides);
    }
}
