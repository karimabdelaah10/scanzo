<?php

namespace App\Http\Controllers\Api;

use App\Actions\Selling\CreateInvoice;
use App\Actions\Selling\VoidInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;
use App\Support\ShopDays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    /** What the receipt document needs, loaded here because lazy loading is off. */
    public const RECEIPT_RELATIONS = ['items', 'business', 'branch', 'seller'];

    /** POST /api/invoices */
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): JsonResponse
    {
        // findOrFail runs through BusinessScope, so another tenant's device is
        // a 404 here even if it reached this far.
        $device = Device::findOrFail($request->input('device_id'));

        $invoice = $createInvoice->handle(
            seller: $request->user(),
            device: $device,
            items: $request->input('items'),
            discountAmount: number_format((float) $request->input('discount_amount', 0), 2, '.', ''),
            paymentMethod: PaymentMethod::tryFrom((string) $request->input('payment_method')) ?? PaymentMethod::Cash,
            customerName: $request->input('customer_name'),
            customerPhone: $request->input('customer_phone'),
            issuedAt: $request->date('issued_at'),
            invoiceId: $request->input('id'),
        );

        // 201 whether this call created the invoice or replayed a sync of one
        // the device had already sent: the id in the body says which.
        $invoice->loadMissing(self::RECEIPT_RELATIONS);

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /** POST /api/invoices/{invoice}/void — cancels it and puts its pieces back. */
    public function void(Request $request, string $invoice, VoidInvoice $voidInvoice): InvoiceResource
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        // Through BusinessScope: another tenant's invoice is a 404.
        $model = $voidInvoice->handle($request->user(), Invoice::findOrFail($invoice), $request->input('reason'));

        return $this->show($request, $model->id);
    }

    /** GET /api/invoices/{invoice} — the receipt, and what can still be returned. */
    public function show(Request $request, string $invoice): InvoiceResource
    {
        $model = Invoice::with([...self::RECEIPT_RELATIONS, 'voidedBy'])
            ->withExists(['saleReturns as has_returns', 'exchangeOf as is_exchange'])
            ->findOrFail($invoice);

        abort_unless($request->user()->canAccessBranch($model->branch_id), 403);

        // A seller opens their own receipts only. Without this the list could
        // be closed while a colleague's receipt stayed one id away.
        abort_if($request->user()->isSeller() && $model->user_id !== $request->user()->id, 403);

        return new InvoiceResource($model);
    }

    /**
     * GET /api/invoices?branch_id=&device_id=&user_id=&date= — newest first,
     * 25 a page. Each row carries its piece count and whether anything was
     * returned against it, counted inside the query so the list never loads
     * invoice lines.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = $this->filtered($request)
            ->with('seller')
            ->withSum('items as items_count', 'quantity')
            ->withExists('saleReturns as has_returns')
            ->latest('issued_at')
            ->paginate(25);

        return InvoiceResource::collection($invoices);
    }

    /**
     * GET /api/invoices/summary?branch_id=&date= — the day's takings.
     *
     * Added up in the database so the browser never receives every invoice of
     * the day just to sum them. Sales and returns are reported side by side and
     * never netted into one number: whoever matches the drawer needs both.
     */
    public function summary(Request $request): JsonResponse
    {
        if (Gate::denies('view-sales')) {
            throw DomainException::notAllowedToViewSales();
        }

        // One grouped query feeds the totals, the payment split and the seller
        // split. The groups are few: methods x sellers x currencies.
        $groups = $this->filtered($request)
            ->where('status', InvoiceStatus::Completed)
            ->selectRaw('payment_method, user_id, currency, COUNT(*) as invoices, SUM(total) as total')
            ->groupBy('payment_method', 'user_id', 'currency')
            ->get();

        $byMethod = [];
        $bySeller = [];
        $count = 0;
        $totalMinor = 0;

        foreach ($groups as $g) {
            $minor = Money::toMinor($g->total);
            $method = $g->payment_method->value;
            $seller = $g->user_id ?? '';

            $count += (int) $g->invoices;
            $totalMinor += $minor;

            $byMethod[$method]['invoices'] = ($byMethod[$method]['invoices'] ?? 0) + (int) $g->invoices;
            $byMethod[$method]['minor'] = ($byMethod[$method]['minor'] ?? 0) + $minor;
            $bySeller[$seller]['invoices'] = ($bySeller[$seller]['invoices'] ?? 0) + (int) $g->invoices;
            $bySeller[$seller]['minor'] = ($bySeller[$seller]['minor'] ?? 0) + $minor;
        }

        $names = User::query()->whereIn('id', array_filter(array_keys($bySeller)))->pluck('name', 'id');

        $returnGroups = $this->withinDay(
            SaleReturn::query()
                ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
                ->unless(
                    $request->user()->role->seesAllBranches(),
                    fn ($q) => $q->where('branch_id', $request->user()->branch_id),
                ),
            $request,
        )
            ->selectRaw('refund_method, COUNT(*) as returns_count, SUM(refund_amount) as total')
            ->groupBy('refund_method')
            ->get();

        $returnsMinor = $returnGroups->sum(fn ($g) => Money::toMinor($g->total));

        return response()->json(['data' => [
            'date' => $request->input('date'),
            'currency' => $groups->first()?->currency ?? $request->user()->business->currency(),
            // More than one means the shop changed currency during this day:
            // the totals below add two currencies, and the screen must say so.
            'currencies' => $groups->pluck('currency')->unique()->values(),
            'invoices' => $count,
            'total' => Money::toDecimal($totalMinor),
            'average' => Money::toDecimal($count > 0 ? (int) round($totalMinor / $count) : 0),
            'by_payment_method' => collect($byMethod)
                ->map(fn ($v, $k) => [
                    'method' => $k,
                    'label' => PaymentMethod::from($k)->label(),
                    'invoices' => $v['invoices'],
                    'total' => Money::toDecimal($v['minor']),
                ])->sortByDesc('invoices')->values(),
            'by_seller' => collect($bySeller)
                ->map(fn ($v, $k) => [
                    'user_id' => $k === '' ? null : $k,
                    'name' => $k === '' ? null : ($names[$k] ?? null),
                    'invoices' => $v['invoices'],
                    'total' => Money::toDecimal($v['minor']),
                ])->sortByDesc(fn ($r) => Money::toMinor($r['total']))->values(),
            'returns' => [
                'count' => (int) $returnGroups->sum('returns_count'),
                'total' => Money::toDecimal($returnsMinor),
                'by_method' => $returnGroups->map(fn ($g) => [
                    'method' => $g->refund_method->value,
                    'label' => $g->refund_method->label(),
                    'count' => (int) $g->returns_count,
                    'total' => Money::toDecimal(Money::toMinor($g->total)),
                ])->values(),
            ],
        ]]);
    }

    /**
     * The invoices a request asks for, already limited to what the caller may
     * see. Branch scoping is enforced here, on the server, for the list and the
     * summary alike.
     */
    private function filtered(Request $request): Builder
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        // A seller is pinned to their own invoices, so the requested user_id is
        // ignored rather than added: two conditions on one column contradict and
        // the list comes back empty. The till can still list "my recent sales"
        // for a return; what is closed is a colleague's takings.
        $userId = $request->user()->isSeller()
            ? $request->user()->id
            : $request->input('user_id');

        $query = Invoice::query()
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->input('device_id')))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('branch_id', $request->user()->branch_id),
            );

        return $this->withinDay($query, $request);
    }

    /**
     * A range on issued_at rather than whereDate(): wrapping the column in
     * DATE() stops MySQL using the (business_id, branch_id, issued_at) index.
     *
     * "Today" is the caller's day, not the server's: timestamps are stored in
     * UTC, and a sale at 1am in Cairo belongs to the shop's day, not to the
     * previous UTC one. The screen sends its own `tz`; an unknown one is ignored.
     */
    private function withinDay(Builder $query, Request $request): Builder
    {
        if (! $request->filled('date')) {
            return $query;
        }

        [$start, $end] = ShopDays::between($request, 'date', 'date');

        return $query->whereBetween('issued_at', [$start, $end]);
    }
}
