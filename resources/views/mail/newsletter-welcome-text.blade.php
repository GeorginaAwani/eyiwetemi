Welcome
@foreach ($letter['lines'] as $line)

{!! $line !!}
@endforeach
@if (count($letter['works']) > 0)

To start with
@foreach ($letter['works'] as $work)

{!! $work['kind'] !!}
{!! $work['title'] !!}
@endforeach
@endif

You get {!! $letter['kinds'] !!}.

Change what you get: {!! $letter['manage'] !!}
Stop: {!! $letter['stop'] !!}

From eyiwetemi.com, Port Harcourt
