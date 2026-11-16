@php
  $page = $template->page();
  $cell = $template->cell();
  $perPage = $template->perPage();
  $isRoll = $template->isRoll();
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>استيكرات — {{ $template->label() }}</title>
{{-- Tokens and the shop's brand, for the screen toolbar only. Nothing on the
     printed label uses a token: the label is black on white, always. --}}
<link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ config('scanzo.assets_version') }}">
<script src="{{ asset('js/brand-boot.js') }}?v={{ config('scanzo.assets_version') }}"></script>
<style>
  /* The page is declared in millimetres so what leaves the printer is the
     size the label roll actually is, with no scaling. */
  @page {
    size: {{ $page['width'] }}mm {{ $page['height'] }}mm;
    margin: {{ $cell['margin'] }}mm;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  html, body {
    font-family: "Segoe UI", Tahoma, system-ui, sans-serif;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* On screen only: a short explanation and the print button. Both are gone
     from the paper. */
  .toolbar {
    padding: 16px; border-bottom: 1px solid #ddd; background: #f4f5f7;
    font-size: 14px; line-height: 1.6;
  }
  .toolbar h1 { font-size: 16px; margin-bottom: 6px; }
  .toolbar button {
    height: 40px; padding: 0 20px; border: none; border-radius: 8px;
    background: var(--c-accent-fill); color: var(--c-on-accent); font-size: 15px; font-weight: 600;
    font-family: inherit; cursor: pointer; margin-top: 10px;
  }
  .toolbar .hint { color: #4b5563; }
  .toolbar table { margin-top: 10px; border-collapse: collapse; font-size: 13px; }
  .toolbar td { padding: 2px 10px 2px 0; }

  .sheet { display: flex; flex-wrap: wrap; column-gap: {{ $cell['gap_x'] }}mm; row-gap: {{ $cell['gap_y'] }}mm; }

  .label {
    width: {{ $cell['width'] }}mm;
    height: {{ $cell['height'] }}mm;
    padding: 1.5mm;
    display: flex;
    align-items: center;
    gap: 1.5mm;
    overflow: hidden;
    /* A hairline on screen to show where the sticker edges are; it does not
       print, because pre-cut sticker sheets already have edges. */
    outline: 0.1mm dashed #ccc;
  }

  .label .codes { display: flex; flex-direction: column; align-items: center; gap: 0.5mm; flex: none; }
  .label .qr { width: {{ $isRoll ? 15 : 13 }}mm; height: {{ $isRoll ? 15 : 13 }}mm; }
  .label .qr svg { width: 100%; height: 100%; display: block; }

  .label .text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.3mm; }
  .label .name {
    font-size: {{ $isRoll ? '2.6mm' : '2.8mm' }};
    font-weight: 700; line-height: 1.15;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .label .variant { font-size: 2.4mm; font-weight: 600; }
  .label .sku { font-size: 2.1mm; font-family: ui-monospace, monospace; letter-spacing: -0.02em; }
  .label .price { font-size: 3.6mm; font-weight: 700; margin-top: 0.3mm; }
  .label .barcode { margin-top: 0.4mm; height: 6mm; }
  .label .barcode svg { width: 100%; height: 100%; display: block; }

  @media print {
    .toolbar { display: none; }
    .label { outline: none; }
    /* Each label is its own page on a roll; on a sheet the rows fill the page
       and the next page starts on its own. */
    @if ($isRoll)
      .label { page-break-after: always; break-after: page; }
      .label:last-child { page-break-after: auto; break-after: auto; }
    @else
      .label:nth-child({{ $perPage }}n) { page-break-after: always; break-after: page; }
      .label:last-child { page-break-after: auto; break-after: auto; }
    @endif
  }
</style>
</head>
<body>

<div class="toolbar">
  <h1>{{ count($labels) }} استيكر — {{ $template->label() }}</h1>
  <p class="hint">
    اضغط طباعة، واختار المقاس
    <strong>{{ $page['width'] }}×{{ $page['height'] }} مم</strong>
    في إعدادات الطابعة، و<strong>الهوامش: بدون</strong>، و<strong>المقاس: 100%</strong> مش "ملاءمة الصفحة".
    لو عاوز ملف PDF تبعته لمكتبة، اختار "حفظ كـ PDF" من نفس النافذة.
  </p>

  <table>
    @foreach ($summary as $row)
      <tr>
        <td><code>{{ $row['sku'] }}</code></td>
        <td>{{ $row['name'] }}</td>
        <td><strong>{{ $row['copies'] }}</strong> استيكر</td>
      </tr>
    @endforeach
  </table>

  <button onclick="window.print()">طباعة</button>
  <p class="hint" style="margin-top: 10px">
    أول مرة على طابعة جديدة؟ <a href="{{ $calibrationUrl }}">اطبع ده الأول وقيسه</a>:
    خط 50 مم يتأكد بيه إن الطابعة مش بتكبّر ولا بتصغّر.
  </p>
</div>

<div class="sheet">
  @foreach ($labels as $item)
    <div class="label">
      <div class="codes">
        <div class="qr">{!! $item['qr'] !!}</div>
      </div>
      <div class="text">
        <div class="name">{{ $item['product_name'] }}</div>
        @if ($item['label'])
          <div class="variant">{{ $item['label'] }}</div>
        @endif
        <div class="sku">{{ $item['sku'] }}</div>
        @if ($showPrice)
          <div class="price">{{ $item['price'] }} ج.م</div>
        @endif
        @if ($showBarcode && $item['barcode'])
          <div class="barcode">{!! $item['barcode'] !!}</div>
        @endif
      </div>
    </div>
  @endforeach
</div>

</body>
</html>
