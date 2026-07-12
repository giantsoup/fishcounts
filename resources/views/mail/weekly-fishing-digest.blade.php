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

<x-mail.condition-summary :conditions="$summary['environmental_condition']" context="Best day" />

**Best previous trips for {{ $summary['species_name'] }}**

@if ($summary['trip_options']->isEmpty())
No matching completed trips with {{ $summary['species_name'] }} catches were found this week.
@else
<x-mail::table>
| Catch date | Boat | Landing | Trip | Count |
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
- {{ $trip['boat_name'] }} - {{ $trip['trip_type'] }} from {{ $trip['landing_name'] }} ({{ $trip['target_count'] }} {{ $summary['species_name'] }} caught {{ $trip['trip_date'] }}) - [{{ $trip['booking_is_direct'] ? 'Book next matching trip' : 'View booking options' }}]({{ $trip['booking_url'] }})
@if ($trip['booking_departure_at_display'])
  Next matching departure: **{{ $trip['booking_departure_at_display'] }}**.
@if ($trip['booking_open_spots'] !== null)
  {{ $trip['booking_open_spots'] }} spots open.
@endif
@if ($trip['booking_availability_pulled_at_display'])
  Availability checked {{ $trip['booking_availability_pulled_at_display'] }}.
@endif
@endif
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
