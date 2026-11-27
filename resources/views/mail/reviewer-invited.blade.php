<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Read {{ $firstTitle }} before everyone else</title>
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
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:16px;">
                            Hi {{ $firstName }},
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:24px;">
                            I’d like you to read {{ $worksList }} before it’s out, and tell me what you think.
                        </td>
                    </tr>
                    @if ($note)
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-style:italic;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:24px;">
                            “{{ $note }}”
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding-bottom:24px;">
                            <a href="{{ $startUrl }}" style="display:inline-block;background:#1E1C1D;color:#FFFFFF;font-family:Archivo,Arial,sans-serif;font-weight:600;font-size:16px;text-decoration:none;padding:14px 28px;border-radius:24px;">Start reading</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:20px;color:#6F6B6D;padding-bottom:40px;">
                            This link works for 14 days.
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;border-top:1px solid #E6E4E4;padding-top:20px;">
                            Georgina
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
