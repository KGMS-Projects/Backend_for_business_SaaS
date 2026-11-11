<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** What a reviewer gets from `php artisan migrate:fresh --seed`. */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed();
    }

    public function test_there_is_a_user_for_each_role_and_a_manager_with_two_direct_reports(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertTrue(User::where('role', $role->value)->exists(), "no {$role->value} user");
        }

        $maya = User::where('email', 'manager@example.com')->firstOrFail();
        $this->assertSame(2, $maya->directReports()->count());
    }

    public function test_about_ten_requests_are_spread_across_every_status(): void
    {
        $this->assertGreaterThanOrEqual(10, ServiceRequest::count());

        $present = ServiceRequest::query()->distinct()->pluck('status')->map(fn ($s) => $s instanceof RequestStatus ? $s->value : $s)->sort()->values()->all();
        $expected = collect(RequestStatus::cases())->map->value->sort()->values()->all();

        $this->assertSame($expected, $present);
    }

    public function test_seeded_requests_have_a_consistent_history(): void
    {
        foreach (ServiceRequest::all() as $request) {
            $activities = $request->activities;
            $this->assertSame('created', $activities->first()->type->value, "request {$request->id} should start with 'created'");

            $lastStatus = $activities->whereNotNull('to_status')->last()->to_status;
            $this->assertSame($request->status, $lastStatus, "request {$request->id}: audit trail ends in a different status than the request");
        }
        $this->assertGreaterThan(20, RequestActivity::count());
    }

    public function test_seeding_does_not_queue_notifications(): void
    {
        Queue::assertNothingPushed();
    }

    public function test_every_seeded_account_can_log_in_with_the_documented_password(): void
    {
        foreach (User::all() as $user) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        }
    }

    public function test_one_seeded_request_is_overdue_to_demonstrate_the_sla_flag(): void
    {
        $this->assertTrue(ServiceRequest::all()->contains(fn (ServiceRequest $r) => $r->isOverdue()));
    }
}
