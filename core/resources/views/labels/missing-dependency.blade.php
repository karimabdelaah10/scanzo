<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 640px; margin: 48px auto; padding: 0 16px; line-height: 1.6; color: #111827; }
  pre { background: #0b1220; color: #e5e7eb; padding: 14px 16px; border-radius: 10px; overflow-x: auto; font-size: 14px; }
  p.muted { color: #4b5563; font-size: 14px; }
</style>
</head>
<body>
  <h1>{{ $title }}</h1>
  <p>Pure PHP, so there is no extension to compile. Run:</p>
<pre>docker exec scanzo-app {{ $command }}</pre>
  <p class="muted">{{ $why }} Reload this page once it is installed.</p>
</body>
</html>
