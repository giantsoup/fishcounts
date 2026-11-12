# Fishcounts

Daily San Diego sportfishing counts, scraped from landing and aggregator sites and parsed into per-boat trip results.

## Fishing

**Trip type**:
The kind of outing a boat ran, such as "1/2 Day AM" or "Overnight".
_Avoid_: Boat trip, trip

**Trip report**:
One boat's results for one trip type on one date: anglers and per-species catch counts.
_Avoid_: Count, boat trip

## Parsing

**Parser error**:
A problem the parser recorded while reading one scraped payload, waiting for review until it is resolved or dismissed.
_Avoid_: Parse failure, diagnostic (in conversation)

**Alias error**:
A parser error where a boat, species, or trip-type name in the source matched nothing known.
_Avoid_: Unknown name error

**Structural error**:
Any parser error that is not an alias error: the parser misread the shape of the report itself.

**Alias**:
An alternate spelling of a known boat, species, or trip type that the parser should treat as that entity.
_Avoid_: Synonym, mapping

**Obvious fix**:
Resolving an alias error by adding an alias, or by creating a clearly real new boat or species, when the raw value is plainly a name.

**Parser bug**:
A defect in the parser's code, shown by a structural error or by an alias error whose raw value is not a name (prose, a run-on, or numbers).
_Avoid_: Parsing issue
