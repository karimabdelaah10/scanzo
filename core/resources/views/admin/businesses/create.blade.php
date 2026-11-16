@extends('admin.layout')
@section('title', 'محل جديد')
@section('content')
<h1>محل جديد</h1>
<form method="post" action="{{ route('admin.businesses.store') }}" enctype="multipart/form-data" class="card">
  @csrf
  <p class="muted">بيعمل المحل والاشتراك والفرع الرئيسي وحساب المالك وأول كاشير مرة واحدة. الباسورد بيتولّد ويتعرض مرة واحدة بس.</p>
  <div class="grid">
    @php
      $fields = [
        ['business_name', 'اسم المحل', 'text', ''],
        ['phone', 'تليفون المحل (اختياري)', 'text', ''],
        ['owner_name', 'اسم المالك', 'text', ''],
        ['owner_email', 'إيميل المالك (للدخول)', 'email', ''],
        ['plan', 'الخطة', 'text', 'trial'],
        ['trial_days', 'مدة الاشتراك الأولي (أيام)', 'number', '14'],
      ];
    @endphp
    @foreach ($fields as [$name, $label, $type, $default])
      <div>
        <label for="{{ $name }}">{{ $label }}</label>
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $default) }}" @required(! in_array($name, ['phone']))>
        @error($name)<div class="err">{{ $message }}</div>@enderror
      </div>
    @endforeach
  </div>
  <h2 style="margin-top:20px">الشكل (اختياري — المالك يقدر يغيّره بعدين)</h2>
  @include('admin.partials.brand-fields', ['brand' => null])
  <div style="margin-top:16px">
    <button class="btn primary" type="submit">إنشاء</button>
    <a class="btn" href="{{ route('admin.businesses.index') }}">رجوع</a>
  </div>
</form>
@endsection
