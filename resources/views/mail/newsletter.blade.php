@php
    $p = $email['palette'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New from Eyiwetemi</title>
    <style>
        @media (max-width: 480px) {
            .nl-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .nl-cv { display: none !important; }
            .nl-title { font-size: 26px !important; line-height: 32px !important; }
        }
        @unless ($email['dark'])
        @media (prefers-color-scheme: dark) {
            .nl-bg { background-color: #1C1A1B !important; }
            .nl-ink { color: #DCD5D3 !important; }
            .nl-text { color: #BDB5B3 !important; }
            .nl-muted { color: #8A8284 !important; }
            .nl-line { border-color: #2F2C2D !important; }
            .nl-accent { color: #D495A8 !important; }
        }
        @endunless
    </style>
</head>
<body class="nl-bg" style="margin:0;padding:0;background-color:{{ $p['bg'] }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="nl-bg" style="background-color:{{ $p['bg'] }};">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                    <tr>
                        <td class="nl-pad" style="padding:44px 48px 40px;font-family:Archivo,Arial,sans-serif;color:{{ $p['text'] }};">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="nl-line" style="font-family:Archivo,Arial,sans-serif;font-weight:900;font-size:24px;line-height:30px;letter-spacing:-0.02em;color:{{ $p['ink'] }};padding-bottom:22px;border-bottom:1px solid {{ $p['line'] }};">
                                        <span class="nl-accent" style="color:{{ $p['accent'] }};">eyi</span><span class="nl-ink" style="color:{{ $p['ink'] }};">wetemi</span>
                                    </td>
                                    <td class="nl-muted nl-line" align="right" style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:20px;color:{{ $p['muted'] }};padding-bottom:22px;border-bottom:1px solid {{ $p['line'] }};vertical-align:baseline;">{{ $email['date'] }}</td>
                                </tr>
                            </table>

                            @if (count($email['lines']) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;">
                                <tr>
                                    @if ($email['photo'])
                                    <td width="40" valign="top" style="width:40px;padding-right:16px;">
                                        <img src="{{ $email['photo'] }}" width="40" height="40" alt="" style="display:block;width:40px;height:40px;border-radius:50%;object-fit:cover;border:0;">
                                    </td>
                                    @endif
                                    <td valign="top" class="nl-ink" style="font-family:Archivo,Arial,sans-serif;font-size:16px;line-height:26px;color:{{ $p['ink'] }};">
                                        @foreach ($email['lines'] as $line)
                                        <p style="margin:0 0 {{ $loop->last ? '0' : '12' }}px;">{{ $line }}</p>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
                                @foreach ($email['items'] as $item)
                                <tr>
                                    <td class="nl-line" style="padding:30px 0;{{ $loop->first ? '' : 'border-top:1px solid '.$p['line'].';' }}">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                @if ($item['cover'])
                                                <td class="nl-cv" width="56" valign="top" style="width:56px;padding-right:22px;">
                                                    <table role="presentation" width="56" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td width="56" height="84" style="width:56px;height:84px;border-radius:3px;background-color:{{ $item['cover']['color'] }};font-size:0;line-height:0;">
                                                                @if ($item['cover']['src'])
                                                                <img src="{{ $item['cover']['src'] }}" width="56" height="84" alt="" style="display:block;width:56px;height:84px;border:0;border-radius:3px;object-fit:cover;">
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                @endif
                                                <td valign="top">
                                                    <div class="nl-muted" style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:18px;color:{{ $p['muted'] }};">{{ $item['kind'] }}</div>
                                                    @if ($item['title'] !== '')
                                                    <h2 class="nl-ink nl-title" style="margin:6px 0 0;font-family:'Libre Caslon Display',Georgia,serif;font-weight:400;font-size:30px;line-height:36px;color:{{ $p['ink'] }};">{{ $item['title'] }}</h2>
                                                    @endif
                                                    <div class="nl-text" style="margin-top:10px;font-family:Newsreader,Georgia,serif;font-size:18px;line-height:30px;color:{{ $p['text'] }};">
                                                        @if ($item['poem'])
                                                        <p style="margin:0;">{!! nl2br(e(implode("\n", $item['excerpt']))) !!}</p>
                                                        @else
                                                        @foreach ($item['excerpt'] as $paragraph)
                                                        <p style="margin:0 0 10px;">{!! nl2br(e($paragraph)) !!}</p>
                                                        @endforeach
                                                        @endif
                                                    </div>
                                                    <a class="nl-accent" href="{{ $item['link'] }}" style="display:inline-block;margin-top:12px;font-family:Archivo,Arial,sans-serif;font-size:15px;line-height:20px;font-weight:600;color:{{ $p['accent'] }};text-decoration:underline;">{{ $item['linkText'] }}</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                <tr>
                                    <td class="nl-line nl-muted" style="padding-top:22px;border-top:1px solid {{ $p['line'] }};font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:21px;color:{{ $p['muted'] }};">
                                        <p style="margin:0 0 6px;">You get {{ $email['kinds'] }}.</p>
                                        <p style="margin:0 0 14px;"><a class="nl-ink" href="{{ $email['manage'] }}" style="color:{{ $p['ink'] }};text-decoration:underline;margin-right:18px;">Change what you get</a><a class="nl-ink" href="{{ $email['stop'] }}" style="color:{{ $p['ink'] }};text-decoration:underline;">Stop</a></p>
                                        <p style="margin:0;">From eyiwetemi.com, Port Harcourt</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
