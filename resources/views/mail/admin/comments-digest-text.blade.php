{!! $count === 1 ? '1 new comment' : $count.' new comments' !!}
@foreach ($places as $place)

{!! $place['title'] !!}
@foreach ($place['lines'] as $line)
{!! $line !!}
@endforeach
@endforeach
@if ($more > 0)

and {!! $more !!} more
@endif

Open comments:
{!! $url !!}

You can turn these emails off on the Comments page.
