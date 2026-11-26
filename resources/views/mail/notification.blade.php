<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#FFFFFF;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFFFFF;">
        <tr>
            <td align="center" style="padding:48px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                    <tr>
                        <td style="font-family:Archivo,Arial,sans-serif;font-weight:900;font-size:22px;letter-spacing:-0.02em;color:#1E1C1D;padding-bottom:40px;">
                            <span style="color:#A24F68;">eyi</span>wetemi
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:'Libre Caslon Display',Georgia,serif;font-size:34px;line-height:40px;color:#1E1C1D;padding-bottom:16px;">
                            {{ $title }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:32px;">
                            {{ $small }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:40px;">
                            <a href="{{ url($href) }}" style="display:inline-block;background:#1E1C1D;color:#FFFFFF;font-family:Archivo,Arial,sans-serif;font-weight:600;font-size:16px;text-decoration:none;padding:14px 28px;border-radius:24px;">{{ $buttonText }}</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:20px;color:#6F6B6D;border-top:1px solid #E6E4E4;padding-top:20px;">
                            You get this because of your notification settings. <a href="{{ url('/settings#notifications') }}" style="color:#A24F68;">Change them</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
