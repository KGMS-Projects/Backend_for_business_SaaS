<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** GET /api/requests: role scoping, search, filters, sorting, pagination, query count. */
class RequestListingTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    /** One request per status for Eli, plus requests for a teammate and for another team. */
    private function populate(): void
    {
        foreach (RequestStatus::cases() as $status) {
            $this->requestIn($status, $this->employee, ['title' => "Eli {$status->value}"]);
        }
        $this->requestIn(RequestStatus::Submitted, $this->teammate, ['title' => 'Tess submitted']);
        $this->requestIn(RequestStatus::Draft, $this->teammate, ['title' => 'Tess draft']);
        $this->requestIn(RequestStatus::Approved, $this->outsider, ['title' => 'Olga approved']);
        $this->requestIn(RequestStatus::Submitted, $this->outsider, ['title' => 'Olga submitted']);
    }

    /** @return list<string> */
    private function titles(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_an_employee_sees_only_their_own_requests_including_drafts(): void
    {
        $this->populate();

        $titles = $this->titles($this->signIn($this->employee)->getJson('/api/requests?per_page=100')->assertOk());

        $this->assertCount(8, $titles);
        $this->assertSame([], array_values(array_filter($titles, fn ($t) => ! str_starts_with($t, 'Eli '))));
        $this->assertContains('Eli draft', $titles);
    }

    public function test_a_manager_sees_their_direct_reports_requests_but_never_drafts_or_other_teams(): void
    {
        $this->populate();

        $titles = $this->titles($this->signIn($this->manager)->getJson('/api/requests?per_page=100')->assertOk());

        $this->assertCount(8, $titles); // Eli's 7 non-draft + Tess submitted
        $this->assertNotContains('Eli draft', $titles);
        $this->assertNotContains('Tess draft', $titles);
        $this->assertContains('Tess submitted', $titles);
        $this->assertNotContains('Olga submitted', $titles);
        $this->assertNotContains('Olga approved', $titles);
    }

    public function test_the_other_manager_only_sees_their_own_team(): void
    {
        $this->populate();

        $this->assertSame(['Olga approved', 'Olga submitted'], $this->titles($this->signIn($this->otherManager)->getJson('/api/requests')->assertOk()));
    }

    public function test_operations_sees_only_the_approved_pipeline(): void
    {
        $this->populate();

        $statuses = collect($this->signIn($this->ops)->getJson('/api/requests?per_page=100')->assertOk()->json('data'))
            ->pluck('status')->unique()->sort()->values()->all();

        $this->assertSame(['approved', 'assigned', 'completed', 'in_progress'], $statuses);
    }

    public function test_search_matches_title_and_description_case_insensitively(): void
    {
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Ergonomic Keyboard', 'description' => 'wrist pain']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Monitor', 'description' => 'needs a KEYBOARD tray']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Badge', 'description' => 'lost it']);

        $titles = $this->titles($this->signIn($this->employee)->getJson('/api/requests?search=keyboard')->assertOk());

        $this->assertSame(['Ergonomic Keyboard', 'Monitor'], $titles);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Raise limit to 100%']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'Raise limit to 1000']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'snake_case rename']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'snakeXcase rename']);

        $signedIn = $this->signIn($this->employee);

        $this->assertSame(['Raise limit to 100%'], $this->titles($signedIn->getJson('/api/requests?search='.urlencode('100%'))));
        $this->assertSame(['snake_case rename'], $this->titles($signedIn->getJson('/api/requests?search=snake_case')));
    }

    public function test_status_filter_accepts_one_several_or_an_array(): void
    {
        $this->populate();
        $signedIn = $this->signIn($this->employee);

        $this->assertSame(['Eli draft'], $this->titles($signedIn->getJson('/api/requests?status=draft')));
        $this->assertSame(['Eli approved', 'Eli draft'], $this->titles($signedIn->getJson('/api/requests?status=draft,approved')));
        $this->assertSame(['Eli approved', 'Eli draft'], $this->titles($signedIn->getJson('/api/requests?status[]=draft&status[]=approved')));
    }

    public function test_priority_and_category_filters(): void
    {
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'A', 'priority' => 'urgent', 'category' => 'access']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'B', 'priority' => 'low', 'category' => 'access']);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'C', 'priority' => 'urgent', 'category' => 'equipment']);

        $this->assertSame(['A', 'C'], $this->titles($this->signIn($this->employee)->getJson('/api/requests?priority=urgent')));
        $this->assertSame(['A'], $this->titles($this->signIn($this->employee)->getJson('/api/requests?priority=urgent&category=access')));
    }

    public function test_bad_filter_values_are_validation_errors_not_silently_ignored(): void
    {
        $this->signIn($this->employee)->getJson('/api/requests?status=teleported&priority=asap&overdue=perhaps')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status.0', 'priority', 'overdue']);
    }

    public function test_sorting_is_whitelisted_and_defaults_to_newest_first(): void
    {
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'B', 'created_at' => now()->subDays(2)]);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'C', 'created_at' => now()->subDay()]);
        $this->requestIn(RequestStatus::Draft, attributes: ['title' => 'A', 'created_at' => now()->subDays(3)]);
        $signedIn = $this->signIn($this->employee);

        $order = fn (string $query) => $signedIn->getJson("/api/requests{$query}")->assertOk()->json('data.*.title');

        $this->assertSame(['C', 'B', 'A'], $order(''));
        $this->assertSame(['A', 'B', 'C'], $order('?sort=title'));
        $this->assertSame(['C', 'B', 'A'], $order('?sort=title&direction=desc'));
        $this->assertSame(['A', 'B', 'C'], $order('?sort=created_at&direction=asc'));
    }

    public function test_sorting_by_an_arbitrary_column_is_rejected(): void
    {
        foreach (['password', 'requester_id; DROP TABLE users', 'status'] as $column) {
            $this->signIn($this->employee)->getJson('/api/requests?sort='.urlencode($column))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['sort']);
        }
        $this->signIn($this->employee)->getJson('/api/requests?direction=sideways')->assertStatus(422);
    }

    public function test_lists_are_paginated_with_metadata(): void
    {
        ServiceRequest::factory()->count(7)->requestedBy($this->employee)->create();

        $this->signIn($this->employee)->getJson('/api/requests?per_page=3&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    }

    public function test_page_size_has_a_sensible_default_and_a_ceiling(): void
    {
        ServiceRequest::factory()->count(20)->requestedBy($this->employee)->create();

        $this->signIn($this->employee)->getJson('/api/requests')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.per_page', 15);
        $this->signIn($this->employee)->getJson('/api/requests?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->signIn($this->employee)->getJson('/api/requests?per_page=0')->assertStatus(422);
    }

    public function test_pagination_keeps_the_filters_in_its_links(): void
    {
        ServiceRequest::factory()->count(5)->requestedBy($this->employee)->state(['title' => 'Keyboard'])->create();

        $next = $this->signIn($this->employee)->getJson('/api/requests?search=keyboard&per_page=2')->json('links.next');

        $this->assertStringContainsString('search=keyboard', $next);
        $this->assertStringContainsString('per_page=2', $next);
    }

    public function test_the_list_costs_the_same_number_of_queries_however_many_rows_it_returns(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($this->manager)->getJson('/api/requests?per_page=100')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->requestIn(RequestStatus::Approved, $this->employee, ['assignee_id' => $this->ops->id]);
        $few = $countQueries();

        foreach (range(1, 12) as $i) {
            $this->requestIn(RequestStatus::Approved, $i % 2 ? $this->employee : $this->teammate, ['assignee_id' => $this->ops->id]);
        }
        $many = $countQueries();

        $this->assertSame($few, $many, 'N+1 detected: query count grew with the number of rows');
        $this->assertLessThanOrEqual(4, $many); // page, total count, requesters, assignees
    }

    public function test_a_list_item_does_not_carry_the_heavy_relations(): void
    {
        $this->requestIn(RequestStatus::Draft);

        $item = $this->signIn($this->employee)->getJson('/api/requests')->json('data.0');

        $this->assertArrayNotHasKey('comments', $item);
        $this->assertArrayNotHasKey('activity', $item);
        $this->assertArrayHasKey('requester', $item);
    }
}
