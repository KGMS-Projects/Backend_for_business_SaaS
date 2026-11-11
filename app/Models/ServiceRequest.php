<?php

namespace App\Models;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use Carbon\CarbonInterface;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $requester_id
 * @property int|null $assignee_id
 * @property string $title
 * @property string $description
 * @property RequestCategory $category
 * @property RequestPriority $priority
 * @property RequestStatus $status
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $requester
 * @property-read User|null $assignee
 */
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    /**
     * Only the fields an employee may set. Status, owner and timestamps are changed
     * exclusively by RequestWorkflow (via forceFill), never through request input.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'description', 'category', 'priority'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => RequestCategory::class,
            'priority' => RequestPriority::class,
            'status' => RequestStatus::class,
            'submitted_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id');
    }

    /** @return HasMany<RequestActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(RequestActivity::class)->orderBy('id');
    }

    /** Past its SLA deadline and still not finished. */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! $this->status->isFinal();
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict a query to the requests this user is allowed to see.
     * ServiceRequestPolicy::view() encodes the same rules for a single model;
     * a test keeps the two in sync.
     *
     * @param  Builder<ServiceRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match (true) {
            // Employees: their own requests, drafts included.
            $user->isEmployee() => $query->where('requester_id', $user->id),
            // Managers: their direct reports' requests, but never private drafts.
            $user->isManager() => $query
                ->where('status', '!=', RequestStatus::Draft->value)
                ->whereIn('requester_id', User::query()->select('id')->where('manager_id', $user->id)),
            // Operations: the approved pipeline.
            default => $query->whereIn('status', array_column(RequestStatus::visibleToOperations(), 'value')),
        };
    }

    /**
     * Apply the (already validated) list filters.
     *
     * @param  Builder<ServiceRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->search((string) $filters['search']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->whereIn('status', $filters['status']))
            ->when(filled($filters['priority'] ?? null), fn (Builder $q) => $q->where('priority', $filters['priority']))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category', $filters['category']))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue());
    }

    /**
     * @param  Builder<ServiceRequest>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        // Escape LIKE wildcards so "100%" searches for the literal text. '!' works on SQLite, MySQL and PostgreSQL.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", [$like]);
        });
    }

    /**
     * @param  Builder<ServiceRequest>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', array_column(RequestStatus::finalStatuses(), 'value'));
    }
}
