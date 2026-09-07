{{-- resources/views/app.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — USPS Labels</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <div id="app"></div>
</body>
</html>
