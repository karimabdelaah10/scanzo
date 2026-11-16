@extends('admin.layout')
@section('title', 'اتعمل المحل')
@section('content')
<h1>اتعمل: {{ $business->name }}</h1>

<div class="alert warn">
  <strong>الباسورد ده بيظهر مرة واحدة بس.</strong> انسخه دلوقتي وابعته للعميل — لو قفلت الصفحة أو عملت refresh مش هيتعرض تاني.
</div>

<div class="card">
  <h2>بيانات دخول المالك</h2>
  <table class="stacks kv">
    <tr><th>الرابط</th><td class="mono">{{ $loginUrl }}</td></tr>
    <tr><th>الإيميل</th><td class="mono">{{ $owner->email }}</td></tr>
    <tr><th>الباسورد</th><td class="mono" style="font-size:18px"><strong>{{ $password }}</strong></td></tr>
  </table>
</div>

<div class="card">
  <p class="muted" style="margin:0">
    الخطة {{ $subscription->plan }} · ينتهي {{ $subscription->ends_at?->toDateString() }}
  </p>
</div>

<a class="btn primary" href="{{ route('admin.businesses.show', $business) }}">صفحة المحل</a>
<a class="btn" href="{{ route('admin.businesses.index') }}">المحلات</a>
@endsection
