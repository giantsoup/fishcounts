<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ApiTokens\MintApiToken;
use App\Actions\ApiTokens\RotateApiToken;
use App\Enums\ApiAbility;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\RotateApiTokenRequest;
use App\Http\Requests\StoreApiTokenRequest;
use App\Http\Requests\UpdateApiTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\ApiTokenAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\NewAccessToken;

/**
 * Agent API tokens in the admin UI. The plaintext is shown exactly once, on the show page
 * right after it is minted or rotated; every other response carries only the masked hint.
 */
class ApiTokenController extends Controller
{
    /** Session flash key carrying the encrypted plaintext from store/rotate to show. */
    public const REVEAL_SESSION_KEY = 'api_token_reveal';

    /** How long after minting the one-time reveal is still honoured. */
    public const REVEAL_WINDOW_SECONDS = 300;

    /** Columns the UI reads; the token hash is never selected. */
    private const DISPLAY_COLUMNS = [
        'id', 'tokenable_type', 'tokenable_id', 'created_by_user_id', 'name', 'token_hint',
        'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
    ];

    public function index(): View
    {
        $this->authorize('viewAny', PersonalAccessToken::class);

        $tokens = $this->userTokens()
            ->with(['tokenable', 'creator'])
            ->latest()
            ->latest('id')
            ->paginate(25);

        return view('admin.api-tokens.index', ['tokens' => $tokens]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PersonalAccessToken::class);

        return view('admin.api-tokens.create', [
            'owners' => User::query()->where('is_active', true)->where('role', Role::Admin)->orderBy('name')->get(['id', 'name', 'email']),
            'defaultOwnerId' => $request->user()->getKey(),
            ...$this->formOptions(),
        ]);
    }

    public function store(StoreApiTokenRequest $request, MintApiToken $mintApiToken): RedirectResponse
    {
        $newToken = $mintApiToken->handle(
            $request->owner(),
            $request->validated('name'),
            $request->abilities(),
            $request->expiresAt(),
            $request->user(),
        );

        ApiTokenAudit::write($request, 'created', $newToken->accessToken);

        return $this->redirectToReveal($request, $newToken, 'Token created.');
    }

    public function show(Request $request, PersonalAccessToken $apiToken): Response
    {
        $this->authorize('view', $apiToken);

        $revealedToken = $this->pullRevealedToken($request, $apiToken);

        $response = response()->view('admin.api-tokens.show', [
            'token' => $this->displayToken($apiToken),
            'revealedToken' => $revealedToken,
        ]);

        if ($revealedToken !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function edit(PersonalAccessToken $apiToken): View
    {
        $this->authorize('update', $apiToken);

        return view('admin.api-tokens.edit', [
            'token' => $this->displayToken($apiToken),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateApiTokenRequest $request, PersonalAccessToken $apiToken): RedirectResponse
    {
        $previousName = $apiToken->name;

        $apiToken->forceFill(['name' => $request->validated('name')])->save();

        ApiTokenAudit::write($request, 'renamed', $apiToken, ['previous_name' => $previousName]);

        return redirect()->route('admin.api-tokens.edit', $apiToken)->with('status', 'Token renamed.');
    }

    public function rotate(RotateApiTokenRequest $request, PersonalAccessToken $apiToken, RotateApiToken $rotateApiToken): RedirectResponse
    {
        $rotation = $rotateApiToken->handle($apiToken, $request->abilities(), $request->expiresAt(), $request->user());

        ApiTokenAudit::write($request, 'rotated', $rotation['token']->accessToken, [
            'previous_token_id' => $rotation['previous']['id'],
            'previous_abilities' => $rotation['previous']['abilities'],
            'previous_expires_at' => $rotation['previous']['expires_at'],
        ]);

        return $this->redirectToReveal($request, $rotation['token'], 'Token rotated. The previous token no longer works.');
    }

    public function destroy(Request $request, PersonalAccessToken $apiToken): RedirectResponse
    {
        $this->authorize('delete', $apiToken);

        $apiToken->delete();

        ApiTokenAudit::write($request, 'revoked', $apiToken);

        return redirect()->route('admin.api-tokens.index')->with('status', 'Token revoked.');
    }

    /**
     * Flash the plaintext, encrypted, for the next request only, then send the admin to it.
     */
    private function redirectToReveal(Request $request, NewAccessToken $newToken, string $status): RedirectResponse
    {
        $request->session()->flash(self::REVEAL_SESSION_KEY, [
            'token_id' => $newToken->accessToken->getKey(),
            'ciphertext' => Crypt::encryptString($newToken->plainTextToken),
            'minted_at' => now()->getTimestamp(),
        ]);

        return redirect()->route('admin.api-tokens.show', $newToken->accessToken->getKey())->with('status', $status);
    }

    private function pullRevealedToken(Request $request, PersonalAccessToken $apiToken): ?string
    {
        $reveal = $request->session()->pull(self::REVEAL_SESSION_KEY);

        if (! is_array($reveal)
            || ($reveal['token_id'] ?? null) !== $apiToken->getKey()
            || now()->getTimestamp() - (int) ($reveal['minted_at'] ?? 0) > self::REVEAL_WINDOW_SECONDS) {
            return null;
        }

        return Crypt::decryptString((string) $reveal['ciphertext']);
    }

    /**
     * Re-read the bound token without its hash, so no view can ever render it.
     */
    private function displayToken(PersonalAccessToken $apiToken): PersonalAccessToken
    {
        return $this->userTokens()->with(['tokenable', 'creator'])->findOrFail($apiToken->getKey());
    }

    /** @return Builder<PersonalAccessToken> */
    private function userTokens(): Builder
    {
        return PersonalAccessToken::query()
            ->select(self::DISPLAY_COLUMNS)
            ->where('tokenable_type', (new User)->getMorphClass());
    }

    /** @return array{abilityOptions: list<ApiAbility>, expiryOptions: list<int>, defaultExpiryDays: int} */
    private function formOptions(): array
    {
        return [
            'abilityOptions' => ApiAbility::cases(),
            'expiryOptions' => StoreApiTokenRequest::EXPIRY_OPTIONS,
            'defaultExpiryDays' => StoreApiTokenRequest::DEFAULT_EXPIRY_DAYS,
        ];
    }
}
