<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'FishCounts') }}</title>
        <link rel="icon" type="image/png" href="{{ Vite::asset('resources/images/fishcounts-favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ Vite::asset('resources/images/fishcounts-favicon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-text antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-background">
            <div class="flex flex-col items-center">
                <a href="/">
                    <x-application-logo @class([
                        'rounded-md object-contain',
                        'h-44 w-44 sm:h-56 sm:w-56' => request()->routeIs('login'),
                        'h-24 w-24' => ! request()->routeIs('login'),
                    ]) />
                </a>

                @if (request()->routeIs('login'))
                    <div class="mt-3 text-center">
                        <h1 class="text-4xl font-bold leading-none sm:text-5xl">
                            <span class="text-primary">FISH</span><span class="text-link">COUNTS</span>
                        </h1>
                        <p class="mt-3 text-sm font-semibold text-primary sm:text-base">
                            <span>TRACK TRENDS.</span>
                            <span>CATCH MORE.</span>
                            <span class="text-danger-accent">GET NOTIFIED.</span>
                        </p>
                    </div>
                @endif
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-surface shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
