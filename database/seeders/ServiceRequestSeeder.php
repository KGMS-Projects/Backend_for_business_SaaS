<?php

namespace Database\Seeders;

use App\Enums\DecisionAction;
use App\Enums\RequestCategory as Category;
use App\Enums\RequestPriority as Priority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Database\Seeder;

/**
 * 11 requests covering every status. They are created by driving RequestWorkflow with the
 * right actors, so each one has a genuine, consistent activity history.
 */
class ServiceRequestSeeder extends Seeder
{
    public function run(RequestWorkflow $workflow): void
    {
        // Don't queue a notification for every seeded transition.
        $notifications = config('service_requests.notifications_enabled');
        config(['service_requests.notifications_enabled' => false]);

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $eli = $user('employee@example.com');
        $emma = $user('employee2@example.com');
        $nina = $user('employee3@example.com');
        $maya = $user('manager@example.com');
        $olivia = $user('ops@example.com');
        $omar = $user('ops2@example.com');

        $create = fn (User $who, string $title, Category $category, Priority $priority, bool $submit = false) => $workflow->create($who, [
            'title' => $title,
            'description' => "{$title}. Created by the demo seeder.",
            'category' => $category->value,
            'priority' => $priority->value,
        ], $submit);

        // 1-2 Draft
        $create($eli, 'Standing desk for my workstation', Category::Equipment, Priority::Low);
        $create($emma, 'Access to the finance reporting dashboard', Category::Access, Priority::Medium);

        // 3-4 Submitted (waiting for Maya)
        $create($eli, 'VPN access for a visiting contractor', Category::Access, Priority::High, submit: true);
        $create($emma, 'Replace flickering monitor', Category::Equipment, Priority::Medium, submit: true);

        // 5 Returned (Maya asked for more detail)
        $returned = $create($eli, 'New laptop for design work', Category::Equipment, Priority::High, submit: true);
        $workflow->decide($returned, $maya, DecisionAction::Return, 'Please add the budget code and the model you need.');

        // 6 Rejected (final)
        $rejected = $create($emma, 'Office plants for the team area', Category::Maintenance, Priority::Low, submit: true);
        $workflow->decide($rejected, $maya, DecisionAction::Reject, 'Not covered by this quarter\'s facilities budget.');

        // 7 Approved (waiting for operations to pick an owner)
        $approved = $create($eli, 'Shared drive access for the marketing folder', Category::Access, Priority::Medium, submit: true);
        $workflow->decide($approved, $maya, DecisionAction::Approve);

        // 8 Assigned (and overdue, to show the SLA flag)
        $assigned = $create($emma, 'Fix leaking tap in the pantry', Category::Maintenance, Priority::Urgent, submit: true);
        $workflow->decide($assigned, $maya, DecisionAction::Approve, 'Urgent, please prioritise.');
        $workflow->assign($assigned, $olivia, $olivia);
        $assigned->forceFill(['due_at' => now()->subDay()])->save();

        // 9 In progress
        $inProgress = $create($eli, 'Install ergonomic keyboard and mouse', Category::Equipment, Priority::Low, submit: true);
        $workflow->decide($inProgress, $maya, DecisionAction::Approve);
        $workflow->assign($inProgress, $olivia, $omar, 'Omar has these in stock.');
        $workflow->changeStatus($inProgress, $omar, RequestStatus::InProgress, 'Picked up from the store room.');

        // 10 Completed (final)
        $completed = $create($emma, 'Reset my e-mail password', Category::ItSupport, Priority::High, submit: true);
        $workflow->decide($completed, $maya, DecisionAction::Approve);
        $workflow->assign($completed, $olivia, $olivia);
        $workflow->changeStatus($completed, $olivia, RequestStatus::InProgress);
        $workflow->changeStatus($completed, $olivia, RequestStatus::Completed, 'Password reset and MFA re-enrolled.');

        // 11 Team B: a request Maya must NOT be able to see or act on.
        $create($nina, 'Onboarding laptop for a new starter', Category::Equipment, Priority::Medium, submit: true);
        $workflow->addComment(
            ServiceRequest::query()->where('requester_id', $eli->id)->where('title', 'VPN access for a visiting contractor')->firstOrFail(),
            $maya,
            'Which days will the contractor be on site?',
        );

        config(['service_requests.notifications_enabled' => $notifications]);
    }
}
