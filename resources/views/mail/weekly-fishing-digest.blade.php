<x-mail::message>
# Weekly fishing digest

Week ending {{ $weekEnding->toFormattedDateString() }}

@forelse ($summaries as $summary)
## {{ $summary['rule_name'] }}

@if (! $summary['has_scores'])
No scores were recorded for {{ $summary['species_name'] }} this week.
@else
<x-mail::panel>
**{{ $summary['species_name'] }}** is currently **{{ $summary['level_label'] }}** with a score of **{{ $summary['score'] }}**.
</x-mail::panel>

<x-mail::table>
| Metric | Value |
| :-- | --: |
| Weekly target fish | {{ $summary['weekly_total'] }} |
| Latest score date | {{ $summary['score_date'] }} |
| Best day | {{ $summary['best_day'] }} |
| Trend | {{ $summary['trend'] }} |
| Boats reporting | {{ $summary['boat_count'] }} |
</x-mail::table>

**Best trip options**

@if ($summary['trip_options']->isEmpty())
No matching boat-level trip counts this week.
@else
<x-mail::table>
| Date | Boat | Landing | Trip | Count |
| :-- | :-- | :-- | :-- | --: |
@foreach ($summary['trip_options'] as $trip)
| {{ $trip['trip_date'] }} | {{ $trip['boat_name'] }} | {{ $trip['landing_name'] }} | {{ $trip['trip_type'] }} | @if ($trip['source_url']) [{{ $trip['target_count'] }} ↗]({{ $trip['source_highlight_url'] ?? $trip['source_url'] }}) @else {{ $trip['target_count'] }} @endif |
@endforeach
</x-mail::table>

**Recommended boats**

@if ($summary['trip_recommendations']->isEmpty())
No booking links are available for the ranked boats.
@else
@foreach ($summary['trip_recommendations'] as $trip)
- {{ $trip['boat_name'] }} - {{ $trip['trip_type'] }} on {{ $trip['trip_date'] }} from {{ $trip['landing_name'] }} ({{ $trip['target_count'] }} {{ $summary['species_name'] }}) - [Book]({{ $trip['booking_url'] }})
@endforeach
@endif
@endif
@endif
@empty
No digest-enabled alert rules are currently configured.
@endforelse

<x-mail::button :url="$scoresUrl">
View scores
</x-mail::button>
</x-mail::message>
