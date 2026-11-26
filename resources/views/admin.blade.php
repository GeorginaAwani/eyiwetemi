<!DOCTYPE html>
<html lang="en" data-theme="night">
    <head>
        <script>try{var t=JSON.parse(localStorage.getItem('eyiAdmTheme'));if(t&&/^(white|paper|grey|night)$/.test(t))document.documentElement.dataset.theme=t}catch(e){}</script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        <title inertia>eyiwetemi · admin</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&family=Libre+Caslon+Display&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,600;1,6..72,400&display=swap" rel="stylesheet">

        <link rel="icon" href="/img/mark/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/img/mark/favicon-32.png" sizes="32x32">

        @viteReactRefresh
        @vite(['resources/js/admin.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
