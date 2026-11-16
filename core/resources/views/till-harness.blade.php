<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Scanzo — تجربة البيع</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<style>
  :root {
    --bg: #f4f5f7; --surface: #fff; --fg: #111827; --muted: #4b5563;
    --line: #dfe3e8; --accent: #0f766e; --bad: #b42318; --badbg: #fdf1f0;
    --ok: #0f5f59; --okbg: #e6f4f1;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--fg); font-size: 15px;
    font-family: "IBM Plex Sans Arabic", system-ui, sans-serif; line-height: 1.5;
  }
  .wrap { max-width: 920px; margin: 0 auto; padding: 16px; }
  .banner {
    background: #fff7e6; border: 1px solid #f0c674; color: #7a4c00;
    padding: 10px 14px; border-radius: 12px; font-size: 13px; margin-bottom: 16px;
  }
  .grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
  @media (min-width: 760px) { .grid { grid-template-columns: 1fr 1fr; } }
  .card { background: var(--surface); border-radius: 16px; padding: 16px; }
  .card h2 { margin: 0 0 12px; font-size: 16px; font-weight: 700; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
  input, select, button {
    font-family: inherit; font-size: 15px; color: inherit;
  }
  input, select {
    width: 100%; height: 44px; padding: 0 12px; border: 1px solid var(--line);
    border-radius: 10px; background: #fff; margin-bottom: 10px;
  }
  button {
    height: 44px; padding: 0 16px; border: none; border-radius: 10px;
    background: var(--accent); color: #fff; font-weight: 600; cursor: pointer;
  }
  button.ghost { background: #fff; border: 1px solid var(--line); color: var(--fg); }
  button:disabled { opacity: .5; cursor: default; }
  .row { display: flex; gap: 8px; align-items: flex-end; }
  .row > * { margin-bottom: 0; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: right; padding: 8px 6px; border-bottom: 1px solid var(--line); }
  th { font-size: 12px; color: var(--muted); font-weight: 600; }
  td.num, th.num { text-align: left; font-variant-numeric: tabular-nums; }
  .msg { padding: 10px 12px; border-radius: 10px; font-size: 14px; margin-bottom: 10px; }
  .msg.bad { background: var(--badbg); color: var(--bad); }
  .msg.ok { background: var(--okbg); color: var(--ok); }
  .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; background: var(--okbg); color: var(--ok); font-size: 12px; font-weight: 600; }
  code { font-family: ui-monospace, Menlo, monospace; font-size: 13px; }
  .total { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .muted { color: var(--muted); font-size: 13px; }
  .skus button { background: #fff; border: 1px solid var(--line); color: var(--fg); height: 32px; padding: 0 10px; font-size: 13px; margin: 0 0 6px 6px; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<div class="wrap">

  <div class="banner">
    صفحة تجريبية للتأكد إن الـ API شغال. مش شاشة البيع الحقيقية — دي هتبقى Vue SPA.
    بتشتغل على بيئة local بس.
  </div>

  <!-- login -->
  <div class="card" id="loginCard">
    <h2>تسجيل الدخول</h2>
    <div class="msg bad" id="loginError" hidden></div>
    <label for="email">الإيميل</label>
    <input id="email" type="email" value="manager@demo-shop.local" autocomplete="username">
    <label for="password">الباسورد</label>
    <input id="password" type="password" value="password" autocomplete="current-password">
    <button id="loginBtn">دخول</button>
    <p class="muted" style="margin-top:10px">
      من الـ seeder: <code>owner@</code> / <code>manager@</code> / <code>seller@demo-shop.local</code> — الباسورد <code>password</code>
    </p>
  </div>

  <!-- app -->
  <div id="app" hidden>
    <div class="card" style="margin-bottom:16px">
      <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <strong id="bizName"></strong>
          <span class="pill" id="roleBadge"></span>
          <div class="muted" id="userLine"></div>
        </div>
        <button class="ghost" id="logoutBtn">خروج</button>
      </div>
      <div class="row" style="margin-top:12px;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px">
          <label for="branch">الفرع</label>
          <select id="branch"></select>
        </div>
        <div style="flex:1;min-width:180px">
          <label for="device">الجهاز</label>
          <select id="device"></select>
        </div>
      </div>
    </div>

    <div class="grid">
      <!-- scan -->
      <div class="card">
        <h2>Scan</h2>
        <div class="msg bad" id="scanError" hidden></div>
        <label for="code">كود القطعة (SKU أو barcode)</label>
        <div class="row">
          <input id="code" placeholder="SKU-10001" autocomplete="off">
          <button id="scanBtn">بحث</button>
        </div>

        <div id="found" hidden style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
          <div style="font-size:18px;font-weight:700" id="fName"></div>
          <div class="muted" id="fLabel"></div>
          <div class="row" style="margin-top:10px;justify-content:space-between">
            <div><span class="total" id="fPrice"></span> <span class="muted">ج.م</span></div>
            <div class="muted" id="fAvail"></div>
          </div>
          <div class="row" style="margin-top:12px">
            <div style="width:110px">
              <label for="qty">الكمية</label>
              <input id="qty" type="number" min="1" value="1">
            </div>
            <button id="addBtn" style="flex:1">أضف للسلة</button>
          </div>
        </div>

        <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--line)">
          <div class="row" style="justify-content:space-between">
            <span class="muted">أكواد موجودة في الفرع</span>
            <button class="ghost" id="loadSkus" style="height:32px;font-size:13px">تحميل</button>
          </div>
          <div class="skus" id="skuList" style="margin-top:8px"></div>
        </div>
      </div>

      <!-- cart -->
      <div class="card">
        <h2>السلة</h2>
        <div class="msg bad" id="saveError" hidden></div>
        <div class="msg ok" id="saveOk" hidden></div>
        <table id="cartTable" hidden>
          <thead>
            <tr><th>القطعة</th><th class="num">كمية</th><th class="num">الإجمالي</th><th></th></tr>
          </thead>
          <tbody id="cartBody"></tbody>
        </table>
        <p class="muted" id="cartEmpty">فاضية. اعمل scan لأول قطعة.</p>

        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
          <label for="discount">خصم (ج.م)</label>
          <input id="discount" type="number" min="0" value="0">
          <div class="row" style="justify-content:space-between;margin-bottom:12px">
            <span>الإجمالي</span>
            <span class="total" id="cartTotal">0.00</span>
          </div>
          <button id="saveBtn" style="width:100%" disabled>حفظ الفاتورة</button>
        </div>
      </div>
    </div>

    <!-- invoices -->
    <div class="card" style="margin-top:16px">
      <div class="row" style="justify-content:space-between">
        <h2 style="margin:0">آخر الفواتير</h2>
        <button class="ghost" id="loadInvoices" style="height:32px;font-size:13px">تحديث</button>
      </div>
      <table style="margin-top:12px">
        <thead><tr><th>رقم</th><th>الوقت</th><th class="num">قطع</th><th class="num">الإجمالي</th></tr></thead>
        <tbody id="invoiceBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var $ = function (id) { return document.getElementById(id); };
  var state = { me: null, cart: [], found: null };

  function money(n) { return (Math.round(Number(n) * 100) / 100).toFixed(2); }

  function show(el, on) { el.hidden = !on; }

  function setMsg(el, text) {
    if (!text) { el.hidden = true; return; }
    el.textContent = text;
    el.hidden = false;
  }

  // One place that reads the API's error shape: {message, code, context}.
  async function api(method, url, body) {
    var res = await fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });

    var data = null;
    try { data = await res.json(); } catch (e) {}

    if (!res.ok) {
      var message = (data && data.message) || ('خطأ ' + res.status);
      if (data && data.errors) {
        message = Object.values(data.errors).map(function (e) { return e[0]; }).join(' · ');
      }
      var err = new Error(message);
      err.code = data && data.code;
      err.context = data && data.context;
      throw err;
    }

    return data;
  }

  function branchId() { return $('branch').value; }
  function deviceId() { return $('device').value; }

  function renderMe(me) {
    state.me = me;
    $('bizName').textContent = me.business.name;
    $('roleBadge').textContent = me.user.role_label;
    $('userLine').textContent = me.user.name + ' · ' + me.user.email;

    $('branch').innerHTML = me.branches.map(function (b) {
      return '<option value="' + b.id + '">' + b.name + ' (' + b.code + ')</option>';
    }).join('');

    renderDevices();
    $('branch').onchange = function () { renderDevices(); $('skuList').innerHTML = ''; };

    show($('loginCard'), false);
    show($('app'), true);
    loadInvoices();
  }

  function renderDevices() {
    var devices = state.me.devices.filter(function (d) { return d.branch_id === branchId(); });
    $('device').innerHTML = devices.map(function (d) {
      return '<option value="' + d.id + '">' + d.code + ' — ' + d.name + '</option>';
    }).join('') || '<option value="">لا يوجد جهاز في الفرع ده</option>';
  }

  function renderCart() {
    var body = $('cartBody');
    body.innerHTML = '';

    state.cart.forEach(function (line, index) {
      var tr = document.createElement('tr');

      var name = document.createElement('td');
      name.textContent = line.name + (line.label ? ' · ' + line.label : '');
      tr.appendChild(name);

      var qty = document.createElement('td');
      qty.className = 'num';
      qty.textContent = line.quantity;
      tr.appendChild(qty);

      var total = document.createElement('td');
      total.className = 'num';
      total.textContent = money(line.price * line.quantity);
      tr.appendChild(total);

      var action = document.createElement('td');
      var del = document.createElement('button');
      del.className = 'ghost';
      del.style.height = '30px';
      del.style.padding = '0 10px';
      del.textContent = 'حذف';
      del.onclick = function () { state.cart.splice(index, 1); renderCart(); };
      action.appendChild(del);
      tr.appendChild(action);

      body.appendChild(tr);
    });

    var subtotal = state.cart.reduce(function (sum, l) { return sum + l.price * l.quantity; }, 0);
    var discount = Math.max(0, Number($('discount').value) || 0);

    $('cartTotal').textContent = money(Math.max(0, subtotal - discount));
    show($('cartTable'), state.cart.length > 0);
    show($('cartEmpty'), state.cart.length === 0);
    $('saveBtn').disabled = state.cart.length === 0;
  }

  async function loadInvoices() {
    try {
      var data = await api('GET', '/api/invoices?branch_id=' + branchId());
      $('invoiceBody').innerHTML = (data.data || []).map(function (inv) {
        var count = (inv.items || []).reduce(function (s, i) { return s + i.quantity; }, 0);
        return '<tr><td><code>' + inv.number + '</code></td><td class="muted">'
          + new Date(inv.issued_at).toLocaleString('ar-EG')
          + '</td><td class="num">' + (count || '—') + '</td><td class="num">' + inv.total + '</td></tr>';
      }).join('') || '<tr><td colspan="4" class="muted">مفيش فواتير</td></tr>';
    } catch (e) { /* the panel is a convenience; a failure here is not fatal */ }
  }

  async function loadSkus() {
    $('skuList').innerHTML = '<span class="muted">...</span>';
    try {
      var data = await api('GET', '/api/stock?branch_id=' + branchId());
      $('skuList').innerHTML = '';
      (data.data || []).slice(0, 12).forEach(function (row) {
        var b = document.createElement('button');
        b.textContent = row.sku + ' (' + row.quantity + ')';
        b.onclick = function () { $('code').value = row.sku; doScan(); };
        $('skuList').appendChild(b);
      });
      if (!(data.data || []).length) {
        $('skuList').innerHTML = '<span class="muted">مفيش مخزون في الفرع ده</span>';
      }
    } catch (e) {
      $('skuList').innerHTML = '<span class="muted">' + e.message + '</span>';
    }
  }

  async function doScan() {
    setMsg($('scanError'), '');
    show($('found'), false);

    var code = $('code').value.trim();
    if (!code) return;

    try {
      var res = await api('GET', '/api/scan?code=' + encodeURIComponent(code) + '&branch_id=' + branchId());
      var v = res.data;
      state.found = v;

      $('fName').textContent = v.product_name;
      $('fLabel').textContent = v.label || '';
      $('fPrice').textContent = money(v.price);
      $('fAvail').textContent = 'متاح ' + v.available;
      $('qty').value = 1;
      $('qty').max = Math.max(1, v.available);
      show($('found'), true);
    } catch (e) {
      setMsg($('scanError'), e.message + (e.code ? ' [' + e.code + ']' : ''));
    }
  }

  $('loginBtn').onclick = async function () {
    setMsg($('loginError'), '');
    $('loginBtn').disabled = true;
    try {
      var me = await api('POST', '/api/login', {
        email: $('email').value,
        password: $('password').value
      });
      renderMe(me);
    } catch (e) {
      setMsg($('loginError'), e.message);
    } finally {
      $('loginBtn').disabled = false;
    }
  };

  $('logoutBtn').onclick = async function () {
    try { await api('POST', '/api/logout'); } catch (e) {}
    location.reload();
  };

  $('scanBtn').onclick = doScan;
  $('code').onkeydown = function (e) { if (e.key === 'Enter') doScan(); };

  $('addBtn').onclick = function () {
    var v = state.found;
    if (!v) return;

    var quantity = Math.max(1, parseInt($('qty').value, 10) || 1);
    var existing = state.cart.filter(function (l) { return l.variant_id === v.id; })[0];

    if (existing) { existing.quantity += quantity; }
    else {
      state.cart.push({
        variant_id: v.id, sku: v.sku, name: v.product_name,
        label: v.label, price: Number(v.price), quantity: quantity
      });
    }

    $('code').value = '';
    $('code').focus();
    show($('found'), false);
    state.found = null;
    renderCart();
  };

  $('discount').oninput = renderCart;
  $('loadSkus').onclick = loadSkus;
  $('loadInvoices').onclick = loadInvoices;

  $('saveBtn').onclick = async function () {
    setMsg($('saveError'), '');
    setMsg($('saveOk'), '');
    $('saveBtn').disabled = true;

    try {
      var res = await api('POST', '/api/invoices', {
        device_id: deviceId(),
        items: state.cart.map(function (l) {
          return { variant_id: l.variant_id, quantity: l.quantity };
        }),
        discount_amount: Math.max(0, Number($('discount').value) || 0),
        payment_method: 'cash'
      });

      setMsg($('saveOk'), 'تم حفظ الفاتورة ' + res.data.number + ' بإجمالي ' + res.data.total + ' ج.م');
      state.cart = [];
      $('discount').value = 0;
      renderCart();
      loadInvoices();
      loadSkus();
    } catch (e) {
      var extra = '';
      if (e.code === 'out_of_stock' && e.context) {
        extra = ' (متاح ' + e.context.available + ' والمطلوب ' + e.context.requested + ')';
      }
      setMsg($('saveError'), e.message + extra + (e.code ? ' [' + e.code + ']' : ''));
    } finally {
      $('saveBtn').disabled = state.cart.length === 0;
    }
  };

  // Already signed in from an earlier visit?
  api('GET', '/api/me').then(renderMe).catch(function () {});
})();
</script>
</body>
</html>
