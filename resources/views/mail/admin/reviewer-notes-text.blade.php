Notes from {!! $firstName !!}
@foreach ($places as $place)

{!! $place['title'] !!}
@foreach ($place['notes'] as $note)

@if ($note['quote'])
{!! $note['quote'] !!}
@endif
@if ($note['suggestion'])
{!! $note['suggestion'] !!}
@endif
@if ($note['text'])
{!! $note['text'] !!}
@endif
@endforeach
@endforeach

Open comments:
{!! $url !!}

You can turn these emails off on the Comments page.
