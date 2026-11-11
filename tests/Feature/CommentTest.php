<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganisation;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use BuildsOrganisation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganisation();
    }

    public function test_the_requester_their_manager_and_operations_can_all_comment_when_they_can_see_the_request(): void
    {
        $inReview = $this->requestIn(RequestStatus::Submitted);
        $approved = $this->requestIn(RequestStatus::Approved);

        $this->signIn($this->employee)->postJson("/api/requests/{$inReview->id}/comments", ['body' => 'Any news?'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Any news?')
            ->assertJsonPath('data.author.id', $this->employee->id);
        $this->signIn($this->manager)->postJson("/api/requests/{$inReview->id}/comments", ['body' => 'Looking now'])->assertCreated();
        $this->signIn($this->ops)->postJson("/api/requests/{$approved->id}/comments", ['body' => 'Picked up'])->assertCreated();

        $this->assertSame(2, $inReview->comments()->count());
    }

    public function test_a_comment_is_part_of_the_audit_trail(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);

        $commentId = $this->signIn($this->manager)->postJson("/api/requests/{$request->id}/comments", ['body' => 'Hello'])->json('data.id');

        $activity = $request->activities()->first();
        $this->assertSame('commented', $activity->type->value);
        $this->assertSame($this->manager->id, $activity->actor_id);
        $this->assertSame($commentId, $activity->meta['comment_id']);
    }

    public function test_comments_cannot_be_added_to_final_requests(): void
    {
        foreach ([RequestStatus::Rejected, RequestStatus::Completed] as $status) {
            $request = $this->requestIn($status);

            $this->signIn($this->employee)->postJson("/api/requests/{$request->id}/comments", ['body' => 'Late thought'])
                ->assertStatus(422)->assertJson(['code' => 'request_final']);
        }

        $this->assertSame(0, Comment::count());
    }

    public function test_the_body_is_required_and_limited(): void
    {
        $request = $this->requestIn(RequestStatus::Submitted);
        $this->signIn($this->employee);

        $this->postJson("/api/requests/{$request->id}/comments", [])->assertStatus(422)->assertJsonValidationErrors(['body']);
        $this->postJson("/api/requests/{$request->id}/comments", ['body' => str_repeat('a', 2001)])->assertStatus(422)->assertJsonValidationErrors(['body']);
    }
}
