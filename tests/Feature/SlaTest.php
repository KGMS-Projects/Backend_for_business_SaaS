<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

/** Stretch goal: SLA due dates and the overdue flag. */
class SlaTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
        Carbon::setTestNow('2026-03-02 09:00:00');
    }

    /** @return iterable<string, array{string, string}> */
    public static function slaByPriority(): iterable
    {
        yield 'urgent: 8h' => ['urgent', '2026-03-02 17:00:00'];
        yield 'high: 24h' => ['high', '2026-03-03 09:00:00'];
        yield 'medium: 72h' => ['medium', '2026-03-05 09:00:00'];
        yield 'low: 120h' => ['low', '2026-03-07 09:00:00'];
    }

    #[DataProvider('slaByPriority')]
    public function test_approval_sets_the_due_date_from_the_priority(string $priority, string $expectedDue): void
    {
        $request = $this->requestIn(RequestStatus::Submitted, attributes: ['priority' => $priority]);

        $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.due_at', Carbon::parse($expectedDue)->toIso8601String());
    }

    public function test_the_sla_does_not_start_before_approval(): void
    {
        $this->signIn($this->employee)->postJson('/api/requests', $this->validPayload(['submit' => true]))
            ->assertCreated()->assertJsonPath('data.due_at', null)->assertJsonPath('data.is_overdue', false);
    }

    public function test_a_request_becomes_overdue_when_its_deadline_passes_and_stops_when_completed(): void
    {
        $request = $this->requestIn(RequestStatus::InProgress, attributes: ['due_at' => now()->addHours(2)]);
        $show = fn () => $this->signIn($this->ops)->getJson("/api/requests/{$request->id}")->json('data.is_overdue');

        $this->assertFalse($show());

        $this->travel(3)->hours();
        $this->assertTrue($show(), 'past the deadline and still open');

        $this->signIn($this->ops)->postJson("/api/requests/{$request->id}/status", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.is_overdue', false);
    }

    public function test_the_overdue_filter_returns_only_open_late_requests(): void
    {
        $late = $this->requestIn(RequestStatus::Assigned, attributes: ['title' => 'Late', 'due_at' => now()->subHour()]);
        $this->requestIn(RequestStatus::Assigned, attributes: ['title' => 'On time', 'due_at' => now()->addHour()]);
        $this->requestIn(RequestStatus::Completed, attributes: ['title' => 'Late but done', 'due_at' => now()->subDay()]);
        $this->requestIn(RequestStatus::Submitted, attributes: ['title' => 'No SLA yet']);

        $titles = $this->signIn($this->ops)->getJson('/api/requests?overdue=1')->assertOk()->json('data.*.title');
        $this->assertSame(['Late'], $titles);
        $this->assertTrue($this->signIn($this->ops)->getJson('/api/requests?overdue=1')->json('data.0.is_overdue'));
        $this->assertSame($late->id, $this->signIn($this->employee)->getJson('/api/requests?overdue=true')->json('data.0.id'));

        $this->assertCount(2, $this->signIn($this->ops)->getJson('/api/requests?overdue=0&status=assigned')->json('data'));
    }
}
