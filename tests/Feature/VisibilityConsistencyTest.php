<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/**
 * The list query (ServiceRequest::scopeVisibleTo) and the single-record rule (ServiceRequestPolicy::view)
 * express the same rules in two places. If they ever drift, someone could list what they cannot open
 * (or open what they cannot list). This test makes drifting impossible to miss.
 */
class VisibilityConsistencyTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    public function test_the_list_scope_and_the_view_policy_agree_for_every_role_and_status(): void
    {
        $this->buildOrganisation();
        foreach ([$this->employee, $this->teammate, $this->outsider] as $requester) {
            foreach (RequestStatus::cases() as $status) {
                $this->requestIn($status, $requester);
            }
        }

        $everyone = [$this->employee, $this->teammate, $this->outsider, $this->manager, $this->otherManager, $this->ops, $this->ops2];

        foreach ($everyone as $user) {
            $listed = ServiceRequest::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
            $viewable = ServiceRequest::all()
                ->filter(fn (ServiceRequest $r) => Gate::forUser($user)->allows('view', $r))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($viewable, $listed, "{$user->name} ({$user->role->value}): list and policy disagree");
            $this->assertNotEmpty($listed, "{$user->name} should see something in this fixture");
        }
    }
}
