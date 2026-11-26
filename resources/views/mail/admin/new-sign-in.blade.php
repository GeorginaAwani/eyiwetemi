<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New sign-in to the admin</title>
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
                            New sign-in
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:16px;">
                            {{ $device }} signed in to the admin on {{ $date }} at {{ $time }} WAT.
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Newsreader,Georgia,serif;font-size:18px;line-height:29px;color:#3B3739;padding-bottom:32px;">
                            If it wasn’t you, change your password and sign out everywhere.
                        </td>
                    </tr>
                    <tr>
                        <td style="font-family:Archivo,Arial,sans-serif;font-size:13px;line-height:20px;color:#6F6B6D;border-top:1px solid #E6E4E4;padding-top:20px;">
                            You’ll get this each time a new device signs in.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
