@php
    $variant = $variant ?? 'desktop';
    $adminNavigationItems = [
        ['label' => 'Overview', 'route' => 'admin.dashboard', 'active' => ['admin.dashboard']],
        ['label' => 'Backfills', 'route' => 'admin.backfills.index', 'active' => ['admin.backfills.*']],
        ['label' => 'Scrape runs', 'route' => 'admin.scrape-runs.index', 'active' => ['admin.scrape-runs.*', 'admin.raw-payloads.*']],
        ['label' => 'Conditions', 'route' => 'admin.conditions.index', 'active' => ['admin.conditions.*']],
        ['label' => 'Parser errors', 'route' => 'admin.parser-errors.index', 'active' => ['admin.parser-errors.*']],
        ['label' => 'Sources', 'route' => 'admin.sources.index', 'active' => ['admin.sources.*']],
        ['label' => 'Boats', 'route' => 'admin.boats.index', 'active' => ['admin.boats.*']],
        ['label' => 'Species', 'route' => 'admin.species-aliases.index', 'active' => ['admin.species-aliases.*']],
        ['label' => 'Trips', 'route' => 'admin.trip-type-aliases.index', 'active' => ['admin.trip-type-aliases.*']],
        ['label' => 'Notification logs', 'route' => 'admin.notification-logs.index', 'active' => ['admin.notification-logs.*']],
        ['label' => 'Failed jobs', 'route' => 'admin.failed-jobs.index', 'active' => ['admin.failed-jobs.*']],
        ['label' => 'Users', 'route' => 'admin.users.index', 'active' => ['admin.users.*']],
    ];
@endphp

@if ($variant === 'mobile')
    <nav class="mt-2 border-t border-gray-200 pt-3" aria-label="Admin navigation">
        <p class="px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Admin tools</p>

        <div class="mt-2 space-y-1">
            @foreach ($adminNavigationItems as $adminNavigationItem)
                @php
                    $isActive = request()->routeIs(...$adminNavigationItem['active']);
                @endphp

                <a
                    href="{{ route($adminNavigationItem['route']) }}"
                    @if ($isActive) aria-current="page" @endif
                    @class([
                        'flex items-center gap-3 border-l-4 py-2 pl-7 pr-4 text-sm font-medium transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-inset',
                        'border-indigo-400 bg-indigo-50 text-indigo-800' => $isActive,
                        'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900' => ! $isActive,
                    ])
                >
                    <span
                        aria-hidden="true"
                        @class([
                            'h-1.5 w-1.5 rounded-full',
                            'bg-indigo-500' => $isActive,
                            'bg-gray-300' => ! $isActive,
                        ])
                    ></span>
                    <span>{{ $adminNavigationItem['label'] }}</span>
                </a>
            @endforeach
        </div>
    </nav>
@else
    <div class="hidden border-b border-gray-200 bg-white sm:block">
        <nav class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8" aria-label="Admin navigation">
            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-1 shadow-sm">
                <div class="hidden shrink-0 border-r border-gray-200 px-3 text-xs font-semibold uppercase tracking-wider text-gray-500 lg:block">
                    Admin
                </div>

                <div class="flex flex-1 flex-wrap items-center gap-1">
                    @foreach ($adminNavigationItems as $adminNavigationItem)
                        @php
                            $isActive = request()->routeIs(...$adminNavigationItem['active']);
                        @endphp

                        <a
                            href="{{ route($adminNavigationItem['route']) }}"
                            @if ($isActive) aria-current="page" @endif
                            @class([
                                'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                'bg-white text-gray-950 shadow-sm ring-1 ring-gray-200' => $isActive,
                                'text-gray-600 hover:bg-white hover:text-gray-900' => ! $isActive,
                            ])
                        >
                            {{ $adminNavigationItem['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </nav>
    </div>
@endif
