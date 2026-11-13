<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">{{ $token->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md bg-white p-4 text-sm text-green-700 shadow-sm">{{ session('status') }}</p>
            @endif

            @if ($revealedToken !== null)
                <section class="space-y-4 bg-white p-6 shadow-sm sm:rounded-lg" x-data="{ copied: false, copyFailed: false }">
                    <header>
                        <h3 class="text-lg font-medium text-gray-900">Copy your new token now</h3>
                        <p class="mt-1 text-sm text-gray-600">It won't be shown again. If you lose it, rotate the token.</p>
                    </header>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-input-label for="revealed-token" value="Token" class="sr-only" />
                        <x-text-input
                            id="revealed-token"
                            x-ref="token"
                            class="block w-full font-mono text-sm"
                            :value="$revealedToken"
                            readonly
                            autocomplete="off"
                            spellcheck="false"
                            x-on:focus="$el.select()"
                        />
                        <x-secondary-button
                            x-on:click="navigator.clipboard.writeText($refs.token.value).then(() => { copyFailed = false; copied = true; setTimeout(() => copied = false, 2000); }).catch(() => { copyFailed = true; $refs.token.select(); })"
                        >
                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="sr-only" role="status" x-text="copied ? 'Token copied to clipboard' : ''"></p>
                    <p class="text-sm text-red-700" role="alert" x-show="copyFailed" style="display: none">Couldn't copy automatically. The token is selected; copy it with Ctrl+C.</p>

                    <p class="text-sm text-gray-600">
                        To store it for the parser-sweep agent on Windows, run
                        <code class="font-mono">cmdkey /generic:fishcounts-agent-api /user:agent /pass:&lt;token&gt;</code>
                        and paste the token in place of <code class="font-mono">&lt;token&gt;</code>.
                    </p>
                </section>
            @else
                <p class="text-sm text-gray-600">The full token is shown only once, right after it is created or rotated. If you didn't copy it, rotate the token to get a new one.</p>
            @endif

            <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                    <dt class="font-medium text-gray-900">Token</dt>
                    <dd class="sm:col-span-2">@include('admin.api-tokens._masked', ['token' => $token])</dd>

                    <dt class="font-medium text-gray-900">Acts as</dt>
                    <dd class="sm:col-span-2">{{ $token->tokenable?->name }} ({{ $token->tokenable?->email }})</dd>

                    <dt class="font-medium text-gray-900">Created by</dt>
                    <dd class="sm:col-span-2">{{ $token->creator ? $token->creator->name.' ('.$token->creator->email.')' : 'CLI or deleted user' }}</dd>

                    <dt class="font-medium text-gray-900">Abilities</dt>
                    <dd class="sm:col-span-2">{{ implode(', ', $token->abilities ?? []) }}</dd>

                    <dt class="font-medium text-gray-900">Created</dt>
                    <dd class="sm:col-span-2">{{ $token->created_at?->format('n/j/Y g:i A') }}</dd>

                    <dt class="font-medium text-gray-900">Last used</dt>
                    <dd class="sm:col-span-2">{{ $token->last_used_at?->format('n/j/Y g:i A') ?? 'Never' }}</dd>

                    <dt class="font-medium text-gray-900">Expires</dt>
                    <dd class="sm:col-span-2">{{ $token->effectiveExpiresAt()?->format('n/j/Y g:i A') ?? 'Never' }}</dd>

                    <dt class="font-medium text-gray-900">Status</dt>
                    <dd class="sm:col-span-2">
                        @if ($token->isExpired())
                            <span class="text-red-700">Expired</span>
                        @else
                            Active
                        @endif
                    </dd>
                </dl>
            </section>

            <div class="flex items-center gap-3">
                <a href="{{ route('admin.api-tokens.edit', $token) }}" class="text-sm font-medium text-blue-700">Manage token</a>
                <a href="{{ route('admin.api-tokens.index') }}" class="text-sm text-gray-600">Back to API tokens</a>
            </div>
        </div>
    </div>
</x-app-layout>
