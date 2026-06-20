<div class="border-b border-gray-200 bg-white">
    <nav class="mx-auto flex max-w-7xl gap-4 overflow-x-auto px-4 py-3 text-sm sm:px-6 lg:px-8" aria-label="Admin navigation">
        <a href="{{ route('admin.dashboard') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.dashboard') ? 'text-gray-950' : 'text-blue-700' }}">Overview</a>
        <a href="{{ route('admin.backfills.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.backfills.*') ? 'text-gray-950' : 'text-blue-700' }}">Backfills</a>
        <a href="{{ route('admin.scrape-runs.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.scrape-runs.*', 'admin.raw-payloads.*') ? 'text-gray-950' : 'text-blue-700' }}">Scrape runs</a>
        <a href="{{ route('admin.parser-errors.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.parser-errors.*') ? 'text-gray-950' : 'text-blue-700' }}">Parser errors</a>
        <a href="{{ route('admin.sources.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.sources.*') ? 'text-gray-950' : 'text-blue-700' }}">Sources</a>
        <a href="{{ route('admin.species-aliases.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.species-aliases.*') ? 'text-gray-950' : 'text-blue-700' }}">Species aliases</a>
        <a href="{{ route('admin.trip-type-aliases.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.trip-type-aliases.*') ? 'text-gray-950' : 'text-blue-700' }}">Trip aliases</a>
        <a href="{{ route('admin.notification-logs.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.notification-logs.*') ? 'text-gray-950' : 'text-blue-700' }}">Notification logs</a>
        <a href="{{ route('admin.failed-jobs.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.failed-jobs.*') ? 'text-gray-950' : 'text-blue-700' }}">Failed jobs</a>
        <a href="{{ route('admin.users.index') }}" class="whitespace-nowrap font-medium {{ request()->routeIs('admin.users.*') ? 'text-gray-950' : 'text-blue-700' }}">Users</a>
    </nav>
</div>
