<?php

namespace App\Http\Requests\Api;

use App\Models\Boat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Merges the duplicate boat named in the body into the boat in the route.
 */
class MergeBoatRequest extends FormRequest
{
    /**
     * Access is decided by the agent-api middleware group and the route's ability.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'duplicate_boat_id' => [
                'required',
                'integer',
                Rule::exists(Boat::class, 'id'),
                Rule::notIn([$this->targetBoat()->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duplicate_boat_id.not_in' => 'A boat cannot be merged into itself.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && ! $this->targetBoat()->is_active) {
                    $validator->errors()->add('duplicate_boat_id', 'Boats can only be merged into an active boat. Reactivate it first.');
                }
            },
        ];
    }

    public function duplicateBoat(): Boat
    {
        return Boat::query()->findOrFail($this->validated('duplicate_boat_id'));
    }

    private function targetBoat(): Boat
    {
        return $this->route('boat');
    }
}
