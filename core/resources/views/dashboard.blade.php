<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
{{-- Session auth needs this on every write; the API client reads it from here. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0F2A44">
<title>{{ config('app.name') }} — لوحة النشاط</title>
{{-- Plex for text, Reem Kufi for headings and the wordmark (dashboard and admin only). --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=Reem+Kufi:wght@600&display=swap">
<link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ config('scanzo.assets_version') }}">
<link rel="stylesheet" href="{{ asset('css/tables.css') }}?v={{ config('scanzo.assets_version') }}">
{{-- Puts the shop's cached brand on <html> before first paint (see the file header). --}}
<script src="{{ asset('js/brand-boot.js') }}?v={{ config('scanzo.assets_version') }}"></script>
@vite(['resources/css/app.css', 'resources/js/dashboard/main.js'])
</head>
<body>
<div id="app"></div>
</body>
</html>
