<?php

namespace App\Http\Requests\Api\Concerns;

/**
 * Page size rules shared by every agent API list request: 50 results per page by default, and a
 * per_page above 100 is rejected with a 422.
 */
trait PaginatesApiLists
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    /**
     * The validated page size, or the default when none was given.
     */
    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * @return array{page: list<string>, per_page: list<string>}
     */
    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
