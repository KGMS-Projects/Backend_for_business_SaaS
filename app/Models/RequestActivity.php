<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\RequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable row of a request's audit trail: who did what, when, with what comment.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $actor_id
 * @property ActivityType $type
 * @property RequestStatus|null $from_status
 * @property RequestStatus|null $to_status
 * @property string|null $comment
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 * @property-read ServiceRequest $serviceRequest
 * @property-read User $actor
 */
class RequestActivity extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['actor_id', 'type', 'from_status', 'to_status', 'comment', 'meta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'from_status' => RequestStatus::class,
            'to_status' => RequestStatus::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
