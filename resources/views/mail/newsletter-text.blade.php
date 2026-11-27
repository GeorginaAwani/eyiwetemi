New from Eyiwetemi
{!! $email['date'] !!}
@foreach ($email['lines'] as $line)

{!! $line !!}
@endforeach
@foreach ($email['items'] as $item)

{!! $item['kind'] !!}
@if ($item['title'] !== '')
{!! $item['title'] !!}
@endif
@if ($item['poem'])

{!! implode("\n", $item['excerpt']) !!}
@else
@foreach ($item['excerpt'] as $paragraph)

{!! $paragraph !!}
@endforeach
@endif

{!! $item['linkText'] !!}: {!! $item['link'] !!}
@endforeach

You get {!! $email['kinds'] !!}.

Change what you get: {!! $email['manage'] !!}
Stop: {!! $email['stop'] !!}

From eyiwetemi.com, Port Harcourt
