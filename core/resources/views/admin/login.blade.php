@extends('admin.layout')
@section('title', 'دخول')
@section('content')
<div class="card" style="max-width:420px;margin:40px auto">
  <h1>دخول المنصة</h1>
  <form method="post" action="{{ route('admin.login') }}">
    @csrf
    <div style="margin-bottom:12px">
      <label for="email">الإيميل</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      @error('email')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div style="margin-bottom:12px">
      <label for="password">الباسورد</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <button class="btn primary" type="submit">دخول</button>
  </form>
</div>
@endsection
