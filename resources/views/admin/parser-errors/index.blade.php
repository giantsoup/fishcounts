<x-app-layout><x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Parser errors</h2></x-slot>
<div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
@forelse ($errors as $error)<p class="py-2 text-sm">{{ $error->created_at->toDateTimeString() }} · {{ $error->error_type }} · {{ $error->message }}</p>@empty<p class="text-sm text-gray-500">No parser errors.</p>@endforelse
{{ $errors->links() }}
</div></div></x-app-layout>
