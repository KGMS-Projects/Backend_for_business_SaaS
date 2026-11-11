<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Not queued itself: it is already sent from inside the queued SendRequestStatusNotification job.
 */
class RequestStatusChanged extends Notification
{
    public function __construct(public RequestActivity $activity) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->activity->serviceRequest;

        $mail = (new MailMessage)
            ->subject("[Request #{$request->id}] {$this->statusLabel()}")
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->summary());

        if ($this->activity->comment) {
            $mail->line('Comment: '.$this->activity->comment);
        }

        return $mail->line("Request: {$request->title}");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->activity->service_request_id,
            'activity_id' => $this->activity->id,
            'from_status' => $this->activity->from_status?->value,
            'to_status' => $this->activity->to_status?->value,
            'message' => $this->summary(),
            'comment' => $this->activity->comment,
        ];
    }

    public function summary(): string
    {
        $request = $this->activity->serviceRequest;
        $actor = $this->activity->actor->name;
        $title = "\"{$request->title}\"";

        return match ($this->activity->to_status) {
            RequestStatus::Submitted => "{$actor} submitted {$title} for your review.",
            RequestStatus::Approved => "{$actor} approved {$title}.",
            RequestStatus::Rejected => "{$actor} rejected {$title}.",
            RequestStatus::Returned => "{$actor} returned {$title} to you for changes.",
            RequestStatus::Assigned => "{$title} was assigned to ".($request->assignee->name ?? 'an operations user').'.',
            RequestStatus::InProgress => "Work has started on {$title}.",
            RequestStatus::Completed => "{$title} has been completed.",
            default => "{$title} was updated.",
        };
    }

    private function statusLabel(): string
    {
        return $this->activity->to_status?->label() ?? 'Updated';
    }
}
