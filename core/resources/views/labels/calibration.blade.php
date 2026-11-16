<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>معايرة الطابعة — {{ $template->label() }}</title>
{{-- Tokens and the shop's brand, for the screen toolbar only. The ruler is black. --}}
<link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ config('scanzo.assets_version') }}">
<script src="{{ asset('js/brand-boot.js') }}?v={{ config('scanzo.assets_version') }}"></script>
<style>
  /* The paper is the template's page, in millimetres, and the ruler is exactly
     50 mm: if it measures 48 or 52 on the paper, the printer scaled it. */
  @page { size: {{ $page['width'] }}mm {{ $page['height'] }}mm; margin: 0; }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { font-family: "Segoe UI", Tahoma, system-ui, sans-serif; color: #000; background: #fff; }

  .toolbar { padding: 16px; border-bottom: 1px solid #ddd; background: #f4f5f7; font-size: 14px; line-height: 1.7; }
  .toolbar h1 { font-size: 16px; margin-bottom: 6px; }
  .toolbar .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
  .toolbar button, .toolbar a.btn {
    height: 40px; padding: 0 20px; border: none; border-radius: 8px; background: var(--c-accent-fill); color: var(--c-on-accent);
    font-size: 15px; font-weight: 600; font-family: inherit; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center;
  }
  .toolbar a.btn.quiet { background: #fff; color: #111827; border: 1px solid #cbd5e1; }

  .paper { width: {{ $page['width'] }}mm; height: {{ $page['height'] }}mm; position: relative; direction: ltr; }
  .ruler { position: absolute; left: {{ ($page['width'] - 50) / 2 }}mm; top: {{ $page['height'] / 2 }}mm; width: 50mm; height: 0; border-top: 0.2mm solid #000; }
  .tick { position: absolute; bottom: 0; width: 0; border-left: 0.1mm solid #000; }
  .caption { position: absolute; top: 1mm; width: 50mm; text-align: center; font-size: 2.6mm; font-weight: 700; }

  @media print {
    .toolbar { display: none; }
  }
  @media screen {
    .paper { margin: 16px; outline: 1px dashed #94a3b8; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <h1>اطبع ده الأول وقيسه — {{ $template->label() }}</h1>
  <p>
    اطبع الصفحة دي من نفس الطابعة اللي هتطبع عليها الاستيكرات، وقيس الخط بمسطرة:
    لازم يطلع <strong>50 مم بالظبط</strong>.
    لو طلع 48 أو 52، فالطابعة بتكبّر أو بتصغّر الصفحة، والمشكلة في <strong>إعدادات الطابعة</strong>
    (المقاس: 100% أو "Actual size"، من غير "ملاءمة الصفحة"، والهوامش: بدون) مش في النظام.
    عدّلها وطبع تاني لحد ما يطلع 50.
  </p>
  <div class="row">
    <button onclick="window.print()">اطبع من المتصفح</button>
    <a class="btn quiet" href="{{ $pdfUrl }}">تحميل PDF</a>
  </div>
</div>

<div class="paper">
  <div class="ruler">
    @for ($mm = 0; $mm <= 50; $mm++)
      <span class="tick" style="left: {{ $mm }}mm; height: {{ $mm % 10 === 0 ? 3 : ($mm % 5 === 0 ? 2 : 1) }}mm"></span>
    @endfor
  </div>
  <div class="caption" style="left: {{ ($page['width'] - 50) / 2 }}mm; top: {{ $page['height'] / 2 + 1 }}mm">50 mm</div>
</div>

</body>
</html>
