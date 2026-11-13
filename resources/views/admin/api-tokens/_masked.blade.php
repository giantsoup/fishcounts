{{-- The only form of a token shown after its one-time reveal: built on the server from the stored hint. --}}
@if ($token->token_hint)
    <span class="sr-only">Token ending in {{ $token->token_hint }}</span>
    <span class="font-mono text-gray-500" aria-hidden="true">{{ $token->maskedToken() }}</span>
@else
    <span class="font-mono text-gray-500" aria-hidden="true">{{ $token->maskedPrefix() }}</span>
    <span class="text-xs text-gray-500">(no hint recorded)</span>
@endif
