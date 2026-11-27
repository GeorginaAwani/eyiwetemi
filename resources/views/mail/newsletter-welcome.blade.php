@php
    $p = $letter['palette'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome</title>
    <style>
        @media (max-width: 480px) {
            .nl-pad { padding-left: 22px !important; padding-right: 22px !important; }
        }
        @unless ($letter['dark'])
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
                                </tr>
                            </table>

                            @if (count($letter['lines']) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;">
                                <tr>
                                    @if ($letter['photo'])
                                    <td width="40" valign="top" style="width:40px;padding-right:16px;">
                                        <img src="{{ $letter['photo'] }}" width="40" height="40" alt="" style="display:block;width:40px;height:40px;border-radius:50%;object-fit:cover;border:0;">
                                    </td>
                                    @endif
                                    <td valign="top" class="nl-ink" style="font-family:Archivo,Arial,sans-serif;font-size:16px;line-height:26px;color:{{ $p['ink'] }};">
                                        @foreach ($letter['lines'] as $line)
                                        <p style="margin:0 0 {{ $loop->last ? '0' : '12' }}px;">{{ $line }}</p>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                            @endif

                            @if (count($letter['works']) > 0)
                            <div class="nl-muted" style="margin-top:26px;font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:18px;color:{{ $p['muted'] }};">To start with</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                @foreach ($letter['works'] as $work)
                                <tr>
                                    <td class="nl-line" style="padding:14px 0;border-bottom:1px solid {{ $p['line'] }};">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="44" valign="top" style="width:44px;padding-right:16px;">
                                                    <table role="presentation" width="44" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td width="44" height="66" style="width:44px;height:66px;border-radius:3px;background-color:{{ $work['cover']['color'] }};font-size:0;line-height:0;">
                                                                @if ($work['cover']['src'])
                                                                <img src="{{ $work['cover']['src'] }}" width="44" height="66" alt="" style="display:block;width:44px;height:66px;border:0;border-radius:3px;object-fit:cover;">
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td valign="middle">
                                                    <div class="nl-muted" style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:18px;color:{{ $p['muted'] }};">{{ $work['kind'] }}</div>
                                                    <h3 class="nl-ink" style="margin:2px 0 0;font-family:'Libre Caslon Display',Georgia,serif;font-weight:400;font-size:22px;line-height:28px;color:{{ $p['ink'] }};">{{ $work['title'] }}</h3>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                <tr>
                                    <td class="nl-line nl-muted" style="padding-top:22px;border-top:1px solid {{ $p['line'] }};font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:21px;color:{{ $p['muted'] }};">
                                        <p style="margin:0 0 6px;">You get {{ $letter['kinds'] }}.</p>
                                        <p style="margin:0 0 14px;"><a class="nl-ink" href="{{ $letter['manage'] }}" style="color:{{ $p['ink'] }};text-decoration:underline;margin-right:18px;">Change what you get</a><a class="nl-ink" href="{{ $letter['stop'] }}" style="color:{{ $p['ink'] }};text-decoration:underline;">Stop</a></p>
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
