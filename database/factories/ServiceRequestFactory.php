<?php

namespace Database\Factories;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a request directly in a given status (a "snapshot"). It deliberately does NOT write
 * activity rows; tests and seeders that need a realistic history drive the RequestWorkflow instead.
 *
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(RequestCategory::cases()),
            'priority' => fake()->randomElement(RequestPriority::cases()),
            'status' => RequestStatus::Draft,
        ];
    }

    public function requestedBy(User $requester): static
    {
        return $this->state(fn () => ['requester_id' => $requester->id]);
    }

    public function status(RequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function assignedTo(User $assignee): static
    {
        return $this->state(fn () => ['assignee_id' => $assignee->id]);
    }
}
