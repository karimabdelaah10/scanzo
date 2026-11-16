<?php

namespace App\Http\Controllers\Api;

use App\Actions\Returns\CreateSaleReturn;
use App\Actions\Returns\LookupInvoiceForReturn;
use App\Actions\Returns\ProcessExchange;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\LookupReceiptRequest;
use App\Http\Requests\StoreSaleReturnRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SaleReturnResource;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\SaleReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    /**
     * GET /api/returns/lookup?number=A1-000123
     *
     * Scanning the receipt's QR starts a return the same way scanning a label
     * starts a sale. The response says what is still returnable per line and
     * whether the invoice is inside the window, so the till can ask for a
     * manager instead of failing.
     */
    public function lookup(LookupReceiptRequest $request, LookupInvoiceForReturn $lookup): JsonResponse
    {
        [
            'invoice' => $invoice,
            'within_window' => $withinWindow,
            'window_days' => $windowDays,
        ] = $lookup->byNumber((string) $request->input('number'), $request->user());

        return response()->json([
            'data' => (new InvoiceResource($invoice))->resolve($request),
            'meta' => [
                'within_return_window' => $withinWindow,
                'return_window_days' => $windowDays,
                'may_override_window' => ! $request->user()->isSeller(),
            ],
        ]);
    }

    /** POST /api/returns — a plain return, or an exchange when exchange_items is sent. */
    public function store(
        StoreSaleReturnRequest $request,
        CreateSaleReturn $createSaleReturn,
        ProcessExchange $processExchange,
    ): JsonResponse {
        // Both run through BusinessScope, so another tenant's row is a 404.
        $device = Device::findOrFail($request->input('device_id'));
        $invoice = Invoice::findOrFail($request->input('invoice_id'));

        $exchangeItems = $request->input('exchange_items');

        if (is_array($exchangeItems) && $exchangeItems !== []) {
            $result = $processExchange->handle(
                user: $request->user(),
                device: $device,
                originalInvoice: $invoice,
                returnItems: $request->input('items'),
                newItems: $exchangeItems,
                paymentMethod: PaymentMethod::tryFrom((string) $request->input('payment_method')) ?? PaymentMethod::Cash,
                reason: $request->input('reason'),
                overrideWindow: $request->boolean('override_window'),
                issuedAt: $request->date('issued_at'),
            );

            return response()->json([
                'data' => (new SaleReturnResource($result['sale_return']))->resolve($request),
                'meta' => [
                    'exchange_invoice' => (new InvoiceResource($result['invoice']->loadMissing(InvoiceController::RECEIPT_RELATIONS)))->resolve($request),
                    'difference' => $result['difference'],
                    'customer_pays' => $result['customer_pays'],
                ],
            ], 201);
        }

        $saleReturn = $createSaleReturn->handle(
            user: $request->user(),
            device: $device,
            invoice: $invoice,
            items: $request->input('items'),
            refundMethod: RefundMethod::tryFrom((string) $request->input('refund_method')) ?? RefundMethod::Cash,
            reason: $request->input('reason'),
            overrideWindow: $request->boolean('override_window'),
            issuedAt: $request->date('issued_at'),
            returnId: $request->input('id'),
        );

        return (new SaleReturnResource($saleReturn))->response()->setStatusCode(201);
    }

    /** GET /api/returns/{saleReturn} */
    public function show(Request $request, string $saleReturn): SaleReturnResource
    {
        $model = SaleReturn::with('items')->findOrFail($saleReturn);

        abort_unless($request->user()->canAccessBranch($model->branch_id), 403);

        return new SaleReturnResource($model);
    }

    /** GET /api/returns?branch_id=&date= */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $returns = SaleReturn::query()
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('issued_at', $request->date('date')))
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('branch_id', $request->user()->branch_id),
            )
            ->latest('issued_at')
            ->paginate(25);

        return SaleReturnResource::collection($returns);
    }
}
