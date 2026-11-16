@extends('admin.layout')
@section('title', 'المحلات')
@section('content')
<div class="row" style="justify-content:space-between;margin-bottom:12px">
  <h1 style="margin:0">المحلات</h1>
  <a class="btn primary" href="{{ route('admin.businesses.create') }}">محل جديد</a>
</div>

<form method="get" class="card row">
  <div style="flex:1;min-width:200px">
    <label for="q">بحث بالاسم</label>
    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}">
  </div>
  <div>
    <label for="status">الحالة</label>
    <select id="status" name="status">
      <option value="">الكل</option>
      @foreach ($statuses as $s)
        <option value="{{ $s->value }}" @selected(($filters['status'] ?? null) === $s->value)>{{ $s->label() }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label for="subscription">الاشتراك</label>
    <select id="subscription" name="subscription">
      <option value="">الكل</option>
      <option value="attention" @selected(($filters['subscription'] ?? null) === 'attention')>محتاج انتباه (منتهي أو من غير اشتراك)</option>
      <option value="expired" @selected(($filters['subscription'] ?? null) === 'expired')>منتهي</option>
      <option value="expiring" @selected(($filters['subscription'] ?? null) === 'expiring')>بينتهي قريب</option>
    </select>
  </div>
  <button class="btn" type="submit">فلتر</button>
</form>

<div class="scroll">
<table class="stacks">
  <thead>
    <tr>
      <th>المحل</th><th>الحالة</th><th>الخطة</th><th>ينتهي</th>
      <th>فروع</th><th>مستخدمين</th><th>أجهزة</th><th>آخر فاتورة</th>
    </tr>
  </thead>
  <tbody>
  @forelse ($businesses as $b)
    @php
      $sub = $b->subscription;
      $subState = $sub?->state();
      $last = $b->last_invoice_at ? \Illuminate\Support\Carbon::parse($b->last_invoice_at) : null;
    @endphp
    <tr>
      <td class="id"><a href="{{ route('admin.businesses.show', $b) }}">{{ $b->name }}</a></td>
      <td data-label="الحالة"><span class="badge {{ $b->status->value }}">{{ $b->status->label() }}</span></td>
      <td data-label="الخطة">{{ $sub?->plan ?? '—' }}</td>
      <td data-label="ينتهي">
        {{ $sub?->ends_at?->toDateString() ?? '—' }}
        @if ($sub === null)
          <span class="badge suspended">مفيش اشتراك</span>
        @elseif ($subState === 'expired')
          <span class="badge suspended">انتهى</span>
        @elseif ($subState === 'expiring')
          <span class="badge trial">
            @php $left = $sub->daysLeft(); @endphp
            {{ $left === 0 ? 'بينتهي النهارده' : "فاضل {$left} يوم" }}
          </span>
        @endif
      </td>
      <td data-label="فروع" class="num">{{ $b->branches_count }}</td>
      <td data-label="مستخدمين" class="num">{{ $b->users_count }}</td>
      <td data-label="أجهزة" class="num">{{ $b->devices_count }}</td>
      <td data-label="آخر فاتورة">{{ $last?->diffForHumans() ?? 'ولا فاتورة' }}</td>
    </tr>
  @empty
    <tr><td class="span muted" colspan="8">مفيش محلات.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
<div class="pager">{{ $businesses->links() }}</div>
@endsection
