{{--
  The brand inputs: two colours, a logo, and a live preview of a button and a
  nav item as they will look. Used by the new-business form and the business
  page. The preview asks the server (GET admin/brand/preview) so the contrast
  rule lives in App\Support\Brand\Contrast and nowhere else. It warns when a
  colour had to be adjusted; it never blocks.
--}}
@php
  $primary = old('brand_primary', $brand['primary'] ?? '');
  $secondary = old('brand_secondary', $brand['secondary'] ?? '');
  $logo = $brand['logo'] ?? null;
@endphp
<div data-brand-form data-preview-url="{{ route('admin.brand.preview') }}">
  <div class="grid">
    <div>
      <label for="brand_primary">اللون الأساسي (الأزرار والعنصر المختار)</label>
      <div class="row" style="align-items:center">
        <input id="brand_primary" name="brand_primary" type="color" value="{{ $primary ?: '#ffffff' }}" data-colour="primary" @disabled($primary === '')>
        <label style="margin:0"><input type="checkbox" name="clear_primary" value="1" data-none="primary" @checked($primary === '')> ألوان المنصة</label>
      </div>
      @error('brand_primary')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div>
      <label for="brand_secondary">اللون التاني (القايمة الجانبية والشريط)</label>
      <div class="row" style="align-items:center">
        <input id="brand_secondary" name="brand_secondary" type="color" value="{{ $secondary ?: '#ffffff' }}" data-colour="secondary" @disabled($secondary === '')>
        <label style="margin:0"><input type="checkbox" name="clear_secondary" value="1" data-none="secondary" @checked($secondary === '')> ألوان المنصة</label>
      </div>
      @error('brand_secondary')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div>
      <label for="logo">اللوجو (PNG أو JPEG أو WebP أو SVG، لحد 512 كيلوبايت و1000 بكسل)</label>
      @if ($logo)<p><img class="logo-now" src="{{ $logo }}" alt="اللوجو الحالي"></p>@endif
      <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
      @if ($logo)<label style="margin-top:8px"><input type="checkbox" name="remove_logo" value="1"> شيل اللوجو الحالي</label>@endif
      @error('logo')<div class="err">{{ $message }}</div>@enderror
    </div>
  </div>

  <div class="brand-preview" data-preview aria-label="معاينة">
    <div class="pv-side"><div class="pv-nav">القسم المختار</div><div class="pv-nav plain">قسم تاني</div></div>
    <div class="pv-main"><span class="pv-btn">زرار</span></div>
  </div>
  <div class="alert warn" data-warning hidden style="margin:12px 0 0"></div>
</div>

<script>
(function () {
  var form = document.currentScript.previousElementSibling;
  var preview = form.querySelector('[data-preview]');
  var warning = form.querySelector('[data-warning]');
  var timer;

  function value(name) {
    var none = form.querySelector('[data-none="' + name + '"]');
    var input = form.querySelector('[data-colour="' + name + '"]');
    input.disabled = none.checked;
    return none.checked ? '' : input.value;
  }

  function refresh() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var q = new URLSearchParams({ primary: value('primary'), secondary: value('secondary') });
      fetch(form.dataset.previewUrl + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          if (!res) return;
          var b = res.data, s = preview.style;
          [['--pv-accent', b.accent], ['--pv-accent-fg', b.accent_fg], ['--pv-side', b.side], ['--pv-side-fg', b.side_fg]]
            .forEach(function (p) { p[1] ? s.setProperty(p[0], p[1]) : s.removeProperty(p[0]); });
          var msgs = [];
          if (b.adjusted.primary) msgs.push('اللون الأساسي فاتح أو غامق زيادة والكتابة فوقه مش هتتقري. الأزرار والعنصر المختار هيستخدموا ' + b.accent + '، واللون المختار بيفضل في الحدود والخلفيات الخفيفة.');
          if (b.adjusted.secondary) msgs.push('اللون التاني فاتح أو غامق زيادة والكتابة فوقه مش هتتقري. القايمة والشريط هيستخدموا ' + b.side + '.');
          warning.textContent = msgs.join(' ');
          warning.hidden = msgs.length === 0;
        })
        .catch(function () { /* the preview is a nicety; saving still works */ });
    }, 150);
  }

  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
})();
</script>
