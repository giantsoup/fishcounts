<?php

namespace App\Http\Requests\Api;

use App\Enums\CanonicalEntityType;
use App\Models\ParserError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adds an alias to the boat, species, or trip type bound in the route. Name and alias clashes are
 * checked by the alias actions themselves, inside their locking transaction.
 */
abstract class StoreAliasRequest extends FormRequest
{
    abstract public function entityType(): CanonicalEntityType;

    /**
     * The boat, species, or trip type the alias is added to.
     */
    abstract public function entity(): Model;

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
            'alias' => ['required', 'string', 'max:255'],
            'parser_error_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(ParserError::class, 'id')],
        ];
    }

    public function normalizedAlias(): string
    {
        return $this->entityType()->normalizeAlias($this->validated('alias'));
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->normalizedAlias() === '') {
                    $validator->errors()->add('alias', 'The alias must contain letters or numbers.');

                    return;
                }

                if (! $this->entity()->getAttribute('is_active')) {
                    $validator->errors()->add('alias', 'Aliases can only be added to an active record. Reactivate it first.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $alias = $this->input('alias');

        if (is_string($alias)) {
            $this->merge(['alias' => str($alias)->squish()->toString()]);
        }
    }
}
