<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="white">
    <head>
        <script>try{var t=localStorage.getItem('eyiTheme');if(t&&/^(white|paper|grey|night)$/.test(t))document.documentElement.dataset.theme=t}catch(e){}</script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @isset($shareMeta)
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Eyiwetemi">
        <meta property="og:title" content="{{ $shareMeta['title'] }}">
        <meta property="og:description" content="{{ $shareMeta['description'] }}">
        <meta property="og:url" content="{{ $shareMeta['url'] }}">
        <meta property="og:image" content="{{ $shareMeta['image'] }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        @endisset

        <title inertia>eyiwetemi</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&family=Libre+Caslon+Display&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,600;1,6..72,400&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=EB+Garamond&family=Literata:ital,wght@0,400;0,600;1,400&text=%C7%8D%C7%8E%C7%8F%C7%90%C7%91%C7%92%C7%93%C7%94%C7%95%C7%96%C7%97%C7%98%C7%99%C7%9A%C7%9B%C7%9C&display=swap">

        <link rel="icon" href="/img/mark/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/img/mark/favicon-32.png" sizes="32x32">
        <link rel="apple-touch-icon" href="/img/mark/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="alternate" type="application/rss+xml" title="eyiwetemi" href="/feed">

        <script>(function(){try{var q=/[?&]splash=1/.test(location.search),sa=matchMedia('(display-mode: standalone)').matches;
if(!(q||(sa&&!sessionStorage.getItem('eyiOpened'))))return;sessionStorage.setItem('eyiOpened','1');
var d=document.createElement('div');d.id='eyiOpen';d.setAttribute('aria-hidden','true');d.innerHTML='<div class="t"><span class="e">ey<span class="i">ı</span></span></div>';
document.documentElement.appendChild(d);var t0=Date.now(),hold=q?2400:600;
addEventListener('load',function(){setTimeout(function(){d.classList.add('out');setTimeout(function(){d.remove()},300)},Math.max(0,hold-(Date.now()-t0)))});}catch(e){}})();</script>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
