<?php

namespace App\Jobs;

use App\Models\RequestActivity;
use App\Notifications\RequestStatusChanged;
use App\Services\RequestRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the right people (database + e-mail) that a request changed state.
 * Runs on the queue so the API response never waits for a mail server.
 */
class SendRequestStatusNotification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** If the activity row has been deleted by the time we run, there is nothing to say. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public RequestActivity $activity) {}

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(RequestRecipients $recipients): void
    {
        $this->activity->loadMissing(['actor', 'serviceRequest.requester.manager', 'serviceRequest.assignee']);

        $users = $recipients->for($this->activity);

        if ($users->isNotEmpty()) {
            Notification::send($users, new RequestStatusChanged($this->activity));
        }
    }
}
