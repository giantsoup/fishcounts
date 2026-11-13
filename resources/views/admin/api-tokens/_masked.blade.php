{{-- The only form of a token shown after its one-time reveal: built on the server from the stored hint. --}}
@if ($token->token_hint)
    <span class="font-mono text-gray-500" aria-label="Token ending in {{ $token->token_hint }}">
        <span aria-hidden="true">{{ $token->maskedToken() }}</span>
    </span>
@else
    <span class="font-mono text-gray-500" aria-label="Token, no hint recorded">
        <span aria-hidden="true">{{ $token->maskedPrefix() }}</span>
    </span>
    <span class="text-xs text-gray-500">(no hint recorded)</span>
@endif
