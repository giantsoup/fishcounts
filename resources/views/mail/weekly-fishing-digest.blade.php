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
| Weekly fish | {{ $summary['weekly_total'] }} |
| Latest score date | {{ $summary['score_date'] }} |
| Best day | {{ $summary['best_day'] }} |
| Trend | {{ $summary['trend'] }} |
| Boats reporting | {{ $summary['boat_count'] }} |
| Fish / angler | {{ $summary['count_per_angler'] }} |
| Data quality | {{ $summary['data_quality'] }} |
</x-mail::table>

**Top boats**

@if ($summary['top_boats']->isEmpty())
No boat detail this week.
@else
<x-mail::table>
| Boat | Fish |
| :-- | --: |
@foreach ($summary['top_boats'] as $boat)
| {{ $boat['name'] }} | {{ $boat['total'] }} |
@endforeach
</x-mail::table>
@endif

**Top landings**

@if ($summary['top_landings']->isEmpty())
No landing detail this week.
@else
<x-mail::table>
| Landing | Fish |
| :-- | --: |
@foreach ($summary['top_landings'] as $landing)
| {{ $landing['name'] }} | {{ $landing['total'] }} |
@endforeach
</x-mail::table>
@endif
@endif
@empty
No digest-enabled alert rules are currently configured.
@endforelse

<x-mail::button :url="$scoresUrl">
View scores
</x-mail::button>
</x-mail::message>
