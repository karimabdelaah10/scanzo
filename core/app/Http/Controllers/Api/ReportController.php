<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;
use App\Support\ShopDays;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /** The longest range one request may ask for. */
    private const MAX_DAYS = 366;

    private const TOP = 10;

    /**
     * GET /api/reports/sales?from=&to=&branch_id=&tz=
     *
     * Everything is added up in the database; the browser gets a few dozen
     * rows however many invoices the range holds. Only completed invoices count.
     * Returns are reported beside the sales and never netted off them.
     */
    public function sales(Request $request): JsonResponse
    {
        if (Gate::denies('view-sales')) {
            throw DomainException::notAllowedToViewSales();
        }

        $user = $request->user();

        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:from',
                // A year is enough for a report; a longer one is a mistake or a scrape.
                fn ($attribute, $value, $fail) => Carbon::parse($request->input('from'))->diffInDays(Carbon::parse($value)) >= self::MAX_DAYS
                    ? $fail('The range cannot be longer than '.self::MAX_DAYS.' days.')
                    : null,
            ],
            'branch_id' => [
                'nullable', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $user->business_id),
            ],
        ]);

        [$start, $end] = ShopDays::between($request, 'from', 'to');

        // A branch manager is pinned to their branch whatever branch_id says.
        $branchId = $user->role->seesAllBranches() ? $request->input('branch_id') : $user->branch_id;

        $invoices = fn (): Builder => Invoice::query()
            ->whereBetween('issued_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', InvoiceStatus::Completed);

        $groups = $invoices()
            ->selectRaw('branch_id, user_id, currency, COUNT(*) as invoices, SUM(total) as total')
            ->groupBy('branch_id', 'user_id', 'currency')
            ->get();

        $count = 0;
        $totalMinor = 0;
        $bySeller = [];
        $byBranch = [];

        foreach ($groups as $g) {
            $minor = Money::toMinor($g->total);
            $n = (int) $g->invoices;
            $seller = $g->user_id ?? '';

            $count += $n;
            $totalMinor += $minor;
            $bySeller[$seller]['invoices'] = ($bySeller[$seller]['invoices'] ?? 0) + $n;
            $bySeller[$seller]['minor'] = ($bySeller[$seller]['minor'] ?? 0) + $minor;
            $byBranch[$g->branch_id]['invoices'] = ($byBranch[$g->branch_id]['invoices'] ?? 0) + $n;
            $byBranch[$g->branch_id]['minor'] = ($byBranch[$g->branch_id]['minor'] ?? 0) + $minor;
        }

        $names = User::query()->whereIn('id', array_filter(array_keys($bySeller)))->pluck('name', 'id');
        $branchNames = \App\Models\Branch::query()->whereIn('id', array_keys($byBranch))->pluck('name', 'id');

        $ranked = fn (array $rows, callable $label) => collect($rows)
            ->map(fn ($v, $k) => $label($k) + [
                'invoices' => $v['invoices'],
                'total' => Money::toDecimal($v['minor']),
                'average' => Money::toDecimal((int) round($v['minor'] / $v['invoices'])),
            ])
            ->sortByDesc(fn ($r) => Money::toMinor($r['total']))
            ->values();

        // Lines of exactly those invoices, so the items obey the same range,
        // branch and status rules by construction.
        $items = fn (): Builder => InvoiceItem::query()
            ->whereIn('invoice_id', $invoices()->select('invoices.id'))
            ->selectRaw('sku, MAX(product_name) as product_name, MAX(variant_label) as variant_label, '
                .'SUM(quantity) as quantity, SUM(line_total - invoice_discount_share) as value, SUM(returned_quantity) as returned')
            ->groupBy('sku')
            ->limit(self::TOP);

        $shape = fn ($r) => [
            'sku' => $r->sku,
            'name' => $r->product_name,
            'variant' => $r->variant_label,
            'quantity' => (int) $r->quantity,
            'returned' => (int) $r->returned,
            'total' => Money::toDecimal(Money::toMinor($r->value)),
        ];

        $returns = SaleReturn::query()
            ->whereBetween('issued_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) as returns_count, SUM(refund_amount) as total')
            ->first();

        return response()->json(['data' => [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'currency' => $groups->first()?->currency ?? $user->business->currency(),
            // More than one means the shop changed currency inside the range.
            'currencies' => $groups->pluck('currency')->unique()->values(),
            'invoices' => $count,
            'total' => Money::toDecimal($totalMinor),
            'average' => Money::toDecimal($count > 0 ? (int) round($totalMinor / $count) : 0),
            'by_seller' => $ranked($bySeller, fn ($k) => [
                'user_id' => $k === '' ? null : $k,
                'name' => $k === '' ? null : ($names[$k] ?? null),
            ]),
            // The owner's view across branches; a manager has only their own.
            'by_branch' => $user->role->seesAllBranches()
                ? $ranked($byBranch, fn ($k) => ['branch_id' => $k, 'name' => $branchNames[$k] ?? null])
                : [],
            // Two rankings, because they answer two questions: what moves, and
            // what brings money in. `total` is the sum of `line_total`, i.e.
            // AFTER each line's own discount (what was really charged for the
            // item) and after the line's stored share of the invoice discount.
            // So what is ranked is what the customer really paid for the item,
            // and the item values of an invoice add up to its total.
            'top_by_quantity' => $items()->orderByDesc('quantity')->orderBy('sku')->get()->map($shape)->values(),
            'top_by_value' => $items()->orderByDesc('value')->orderBy('sku')->get()->map($shape)->values(),
            'returns' => [
                'count' => (int) $returns->returns_count,
                'total' => Money::toDecimal(Money::toMinor($returns->total)),
            ],
        ]]);
    }
}
