<?php

namespace App\Http\Requests;

use App\Enums\DecisionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(DecisionAction::class)],
            // Approving needs no explanation; rejecting or returning does.
            'comment' => [
                Rule::requiredIf(fn () => DecisionAction::tryFrom((string) $this->input('decision'))?->requiresComment() ?? false),
                'nullable', 'string', 'max:2000',
            ],
        ];
    }

    public function action(): DecisionAction
    {
        return DecisionAction::from($this->validated('decision'));
    }
}
