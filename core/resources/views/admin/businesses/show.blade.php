@extends('admin.layout')
@section('title', $business->name)
@section('content')
@php
  use App\Enums\BusinessStatus;
  use App\Enums\SubscriptionStatus;
  $s = $subscription;
  $date = fn ($d) => $d?->toDateString();
  $labels = ['branches' => 'الفروع', 'users' => 'المستخدمين (الفعّالين)', 'devices' => 'الأجهزة (الفعّالة)'];
@endphp

<div class="row" style="justify-content:space-between;margin-bottom:12px">
  <h1 style="margin:0">{{ $business->name }}
    <span class="badge {{ $business->status->value }}">{{ $business->status->label() }}</span></h1>
  <a class="btn" href="{{ route('admin.businesses.index') }}">كل المحلات</a>
</div>
<p class="muted">
  {{ $business->slug }}@if ($business->phone) · {{ $business->phone }}@endif
  · آخر فاتورة: {{ $lastInvoiceAt?->diffForHumans() ?? 'ولا فاتورة' }}
</p>

@if ($s === null)
  <div class="alert bad">
    <strong>المحل ده مفيهوش اشتراك أصلاً.</strong> مفيش خطة ولا تاريخ انتهاء ولا حدود، ومفيش بانر عند المالك —
    يعني محل شغّال من غير ما حد يدفع عنه ومحدش هيلاحظ. اضبط الخطة والتواريخ في نموذج «الاشتراك» تحت واضغط «إضافة اشتراك»
    <a href="#subscription-form">(روح للنموذج)</a>. لحد وقتها السماح بالأوفلاين من الإعداد العام.
  </div>
@elseif ($s->ends_at?->isPast())
  <div class="alert warn">تاريخ الانتهاء عدّى. <strong>ده مبيوقفش البيع تلقائياً</strong> — النظام مبيفحصش تاريخ الانتهاء ولا حالة الاشتراك. اللي بيوقف هو «موقوف» تحت.</div>
@endif

<div class="card">
  <h2>الحالة</h2>
  <form method="post" action="{{ route('admin.businesses.status', $business) }}" class="row"
        onsubmit="return this.status.value !== 'suspended' || confirm('إيقاف المحل بيمنع البيع والمرتجعات فوراً. الدخول وشاشات المخزون والكتالوج بتفضل شغالة. متأكد؟')">
    @csrf @method('PUT')
    <div>
      <label for="status">حالة المحل</label>
      <select id="status" name="status">
        @foreach ($statuses as $st)
          <option value="{{ $st->value }}" @selected($business->status === $st)>{{ $st->label() }}</option>
        @endforeach
      </select>
    </div>
    <button class="btn danger" type="submit">حفظ الحالة</button>
  </form>
  <p class="muted" style="margin:10px 0 0">
    «موقوف» معناه قراءة فقط: المحل يدخل ويشوف بياناته، وأي تعديل بيترفض (<code>business_suspended</code>)
    — بيع، مرتجع، كتالوج، مخزون، فريق، إعدادات. الخروج بس مسموح.
    انتهاء الاشتراك لوحده مبيوقفش حاجة؛ بيظهر للمالك بس.
  </p>
</div>

<form method="post" action="{{ route('admin.businesses.brand', $business) }}" enctype="multipart/form-data" class="card">
  @csrf @method('PUT')
  <h2>الشكل (الألوان واللوجو)</h2>
  @include('admin.partials.brand-fields', ['brand' => $brand])
  <div style="margin-top:12px"><button class="btn primary" type="submit">حفظ الشكل</button></div>
</form>

<div class="card">
  <h2>الاستخدام مقابل الحدود</h2>
  <div class="grid">
    @foreach ($usage as $key => $u)
      <div>
        <div class="muted">{{ $labels[$key] }}</div>
        <div style="font-size:22px" @class(['over' => $u['limit'] !== null && $u['used'] >= $u['limit']])>
          {{ $u['used'] }} / {{ $u['limit'] ?? '∞' }}
        </div>
      </div>
    @endforeach
  </div>
</div>

<form method="post" id="subscription-form" action="{{ route('admin.businesses.subscription', $business) }}" class="card">
  @csrf @method('PUT')
  <h2>الاشتراك@if ($s === null) — جديد@endif</h2>
  @if ($errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif
  <div class="grid">
    <div><label for="plan">الخطة</label>
      <input id="plan" name="plan" value="{{ old('plan', $s?->plan ?? 'trial') }}" required></div>
    <div><label for="sub_status">حالة الاشتراك</label>
      <select id="sub_status" name="status">
        @foreach (SubscriptionStatus::cases() as $ss)
          <option value="{{ $ss->value }}" @selected(old('status', $s?->status?->value ?? 'active') === $ss->value)>{{ $ss->value }}</option>
        @endforeach
      </select></div>
    <div><label for="starts_at">يبدأ</label>
      <input id="starts_at" name="starts_at" type="date" value="{{ old('starts_at', $date($s?->starts_at) ?? now()->toDateString()) }}" required></div>
    <div><label for="ends_at">ينتهي (فاضي = مفتوح)</label>
      <input id="ends_at" name="ends_at" type="date" value="{{ old('ends_at', $date($s?->ends_at)) }}"></div>
    <div><label for="offline_grace_days">سماح الأوفلاين (أيام)</label>
      <input id="offline_grace_days" name="offline_grace_days" type="number" min="1" value="{{ old('offline_grace_days', $s?->offline_grace_days ?? config('scanzo.offline_grace_days')) }}" required></div>
    <div><label for="max_branches">أقصى فروع (فاضي = مفتوح)</label>
      <input id="max_branches" name="max_branches" type="number" min="1" value="{{ old('max_branches', $s?->max_branches) }}"></div>
    <div><label for="max_users">أقصى مستخدمين</label>
      <input id="max_users" name="max_users" type="number" min="1" value="{{ old('max_users', $s?->max_users) }}"></div>
    <div><label for="max_devices">أقصى أجهزة</label>
      <input id="max_devices" name="max_devices" type="number" min="1" value="{{ old('max_devices', $s?->max_devices) }}"></div>
  </div>
  <div style="margin-top:12px"><label for="note">ملاحظة</label>
    <textarea id="note" name="note">{{ old('note', $s?->note) }}</textarea></div>
  <div style="margin-top:12px"><button class="btn primary" type="submit">{{ $s === null ? 'إضافة اشتراك' : 'حفظ الاشتراك' }}</button></div>
</form>
@endsection
