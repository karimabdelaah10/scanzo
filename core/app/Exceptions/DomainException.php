<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A rule the till broke. Renders as a 422 with a machine-readable code, so the
 * selling screen can react to the specific case (show "out of stock" on a line
 * rather than a generic failure).
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context,
        ], $this->status);
    }

    public static function variantNotFound(string $sku): self
    {
        return new self('variant_not_found', "No item with the code {$sku}.", ['sku' => $sku], 404);
    }

    public static function variantInactive(string $sku): self
    {
        return new self('variant_inactive', "The item {$sku} is no longer on sale.", ['sku' => $sku]);
    }

    public static function outOfStock(string $sku, int $requested, int $available): self
    {
        return new self('out_of_stock', "Only {$available} left of {$sku}, {$requested} requested.", [
            'sku' => $sku,
            'requested' => $requested,
            'available' => $available,
        ]);
    }

    public static function businessSuspended(): self
    {
        return new self('business_suspended', 'This subscription is suspended. Your data is still available to read, but nothing can be changed until it is switched back on.', [], 403);
    }

    public static function deviceOutOfGrace(int $graceDays): self
    {
        return new self('device_out_of_grace', "This device has not synced in {$graceDays} days and cannot sell until it does.", [
            'grace_days' => $graceDays,
        ], 403);
    }

    public static function branchNotAllowed(): self
    {
        return new self('branch_not_allowed', 'You cannot sell in this branch.', [], 403);
    }

    public static function emptyCart(): self
    {
        return new self('empty_cart', 'An invoice needs at least one item.');
    }

    public static function discountTooLarge(string $discount, string $subtotal): self
    {
        return new self('discount_too_large', "A discount of {$discount} is more than the subtotal of {$subtotal}.", [
            'discount' => $discount,
            'subtotal' => $subtotal,
        ]);
    }

    public static function lineDiscountTooLarge(string $sku, string $discount, string $lineAmount): self
    {
        return new self('line_discount_too_large', "A discount of {$discount} on {$sku} is more than the line's {$lineAmount}.", [
            'sku' => $sku,
            'discount' => $discount,
            'line_amount' => $lineAmount,
        ]);
    }

    public static function lineDiscountsNotAllowedForSellers(): self
    {
        return new self('line_discount_not_allowed', 'Only a branch manager can discount a line in this shop.', [], 403);
    }

    public static function lineDiscountOverLimit(string $sku, int $maxPercent): self
    {
        return new self('line_discount_over_limit', "A seller can take at most {$maxPercent}% off a line ({$sku}).", [
            'sku' => $sku,
            'max_percent' => $maxPercent,
        ], 403);
    }

    public static function invoiceDiscountNotAllowedForSellers(): self
    {
        return new self('invoice_discount_not_allowed', 'Only a branch manager can discount an invoice in this shop.', [], 403);
    }

    public static function invoiceDiscountOverLimit(int $maxPercent): self
    {
        return new self('invoice_discount_over_limit', "A seller can take at most {$maxPercent}% off an invoice, line discounts and invoice discount together.", [
            'max_percent' => $maxPercent,
        ], 403);
    }

    public static function notAllowedToManageCatalogue(): self
    {
        return new self('catalogue_not_allowed', 'You cannot change the catalogue.', [], 403);
    }

    public static function transferToSameBranch(): self
    {
        return new self('transfer_same_branch', 'A transfer needs two different branches.');
    }

    public static function transferFromOtherBranch(): self
    {
        return new self('transfer_from_other_branch', 'You can only send stock out of your own branch.', [], 403);
    }

    public static function invalidTransferQuantity(): self
    {
        return new self('invalid_transfer_quantity', 'A transfer quantity must be at least 1.');
    }

    /** Confirmed, cancelled, or taken by someone else a second ago. */
    public static function transferNotInTransit(string $status): self
    {
        return new self('transfer_not_in_transit', 'This transfer is no longer in transit.', ['status' => $status], 409);
    }

    /** The sending branch confirming its own shipment would make the confirmation mean nothing. */
    public static function transferReceiveNotAllowed(): self
    {
        return new self('transfer_receive_not_allowed', 'Only the receiving branch\'s manager or the owner can confirm receipt.', [], 403);
    }

    public static function transferCancelNotAllowed(): self
    {
        return new self('transfer_cancel_not_allowed', 'Only the sending branch\'s manager or the owner can cancel a transfer.', [], 403);
    }

    public static function transferReceivedOverSent(string $sku, int $sent, int $received): self
    {
        return new self('transfer_received_over_sent', "{$sku}: {$received} received but only {$sent} were sent.", [
            'sku' => $sku, 'sent' => $sent, 'received' => $received,
        ]);
    }

    /** A receipt names every line of the transfer, once each: nothing is received by omission. */
    public static function transferReceiptLinesMismatch(): self
    {
        return new self('transfer_receipt_lines_mismatch', 'The receipt must list every item of the transfer, once each.');
    }

    public static function transferSettleNotAllowed(): self
    {
        return new self('transfer_settle_not_allowed', 'Only the owner, or the manager of the branch the pieces went into, can settle a shortage.', [], 403);
    }

    public static function transferSettleBranchInvalid(): self
    {
        return new self('transfer_settle_branch_invalid', "The pieces can only go into the transfer's sending or receiving branch.");
    }

    public static function transferSettleReasonRequired(): self
    {
        return new self('transfer_settle_reason_required', 'A reason is required to settle a shortage.');
    }

    public static function transferSettleNothingOpen(): self
    {
        return new self('transfer_settle_nothing_open', 'This transfer has no open shortage.', [], 409);
    }

    public static function transferSettleOverShort(string $sku, int $open, int $requested): self
    {
        return new self('transfer_settle_over_short', "{$sku}: {$requested} to settle but only {$open} are still short.", [
            'sku' => $sku, 'open' => $open, 'requested' => $requested,
        ]);
    }

    public static function branchHasTransfers(string $name): self
    {
        return new self('branch_has_transfers', "{$name} has stock transfers recorded. Switch it off instead of deleting it.", [
            'name' => $name,
        ]);
    }

    public static function notAllowedToManageStock(): self
    {
        return new self('stock_not_allowed', 'You cannot change stock.', [], 403);
    }

    public static function notAllowedToViewSales(): self
    {
        return new self('sales_not_allowed', 'You cannot view the sales summary.', [], 403);
    }

    public static function duplicateSku(string $sku): self
    {
        return new self('duplicate_sku', "The code {$sku} is already used by another item.", ['sku' => $sku]);
    }

    public static function productNeedsAVariant(): self
    {
        return new self('product_needs_a_variant', 'A product needs at least one size or colour to be sold.');
    }

    public static function variantStillInStock(string $sku, int $onHand): self
    {
        return new self('variant_still_in_stock', "{$sku} still has {$onHand} on the shelf. Take it off sale instead of deleting it.", [
            'sku' => $sku,
            'on_hand' => $onHand,
        ]);
    }

    public static function notAllowedToManageBusiness(): self
    {
        return new self('business_not_allowed', 'Only the owner can change this.', [], 403);
    }

    public static function accountSwitchedOff(): self
    {
        return new self('account_inactive', 'This account has been switched off.', [], 401);
    }

    public static function planLimitReached(string $what, int $limit): self
    {
        return new self('plan_limit_reached', "Your plan allows {$limit} {$what}.", [
            'what' => $what,
            'limit' => $limit,
        ]);
    }

    public static function cannotRemoveLastBranch(): self
    {
        return new self('last_branch', 'A business needs at least one branch.');
    }

    public static function cannotRemoveLastOwner(): self
    {
        return new self('last_owner', 'A business needs at least one owner.');
    }

    public static function cannotChangeOwnAccount(string $reason): self
    {
        return new self('own_account', $reason, [], 403);
    }

    /** Deleting the seller would blank out who sold what on every invoice. */
    public static function userHasHistory(string $name): self
    {
        return new self('user_has_history', "{$name} appears on invoices. Switch the account off instead of deleting it.", [
            'name' => $name,
        ]);
    }

    public static function deviceHasHistory(string $code): self
    {
        return new self('device_has_history', "Till {$code} has invoices against it. Switch it off instead of deleting it.", [
            'code' => $code,
        ]);
    }

    public static function branchHasHistory(string $name): self
    {
        return new self('branch_has_history', "{$name} has sales recorded against it. Switch it off instead of deleting it.", [
            'name' => $name,
        ]);
    }

    public static function nothingToPrint(): self
    {
        return new self('nothing_to_print', 'No labels to print: nothing was selected, or none of it is in stock.');
    }

    public static function tooManyLabels(int $requested, int $limit): self
    {
        return new self('too_many_labels', "That is {$requested} labels. Print at most {$limit} at a time.", [
            'requested' => $requested,
            'limit' => $limit,
        ]);
    }

    public static function invoiceNotFound(string $number): self
    {
        return new self('invoice_not_found', "No invoice numbered {$number}.", ['number' => $number], 404);
    }

    public static function invoiceVoided(string $number): self
    {
        return new self('invoice_voided', "Invoice {$number} was voided and cannot be returned against.", [
            'number' => $number,
        ]);
    }

    public static function outsideReturnWindow(string $number, int $windowDays): self
    {
        return new self('outside_return_window', "Invoice {$number} is past the {$windowDays}-day return window. A branch manager can still accept it.", [
            'number' => $number,
            'window_days' => $windowDays,
        ]);
    }

    /** Stock is only a ledger, so its rows are the only record of what was on the shelf. */
    public static function branchHasStockHistory(string $name): self
    {
        return new self('branch_has_stock_history', "{$name} has stock history. Deleting it would erase that record. Switch it off instead.", [
            'name' => $name,
        ]);
    }

    public static function returnsNotAllowedForSellers(): self
    {
        return new self('returns_not_allowed', 'Only a branch manager can issue returns in this shop.', [], 403);
    }

    public static function windowOverrideNotAllowed(): self
    {
        return new self('window_override_not_allowed', 'Only a branch manager can accept a return past the window.', [], 403);
    }

    public static function returnWithoutInvoiceNotAllowed(): self
    {
        return new self('return_without_invoice_not_allowed', 'This shop does not accept returns without the invoice.');
    }

    public static function emptyReturn(): self
    {
        return new self('empty_return', 'A return needs at least one item.');
    }

    public static function lineNotOnInvoice(string $invoiceItemId): self
    {
        return new self('line_not_on_invoice', 'That line is not on this invoice.', [
            'invoice_item_id' => $invoiceItemId,
        ], 404);
    }

    /** The rule that stops the same piece coming back twice. */
    public static function alreadyReturned(string $sku, int $requested, int $remaining): self
    {
        return new self('already_returned', $remaining === 0
            ? "All of {$sku} on this invoice has already been returned."
            : "Only {$remaining} of {$sku} can still be returned, {$requested} requested.", [
                'sku' => $sku,
                'requested' => $requested,
                'remaining' => $remaining,
            ]);
    }

    public static function notAllowedToVoid(): self
    {
        return new self('void_not_allowed', 'Only a branch manager or the owner can cancel an invoice.', [], 403);
    }

    public static function voidReasonRequired(): self
    {
        return new self('void_reason_required', 'Say why the invoice is being cancelled.');
    }

    public static function invoiceAlreadyVoided(string $number): self
    {
        return new self('invoice_already_voided', "Invoice {$number} is already cancelled.", ['number' => $number]);
    }

    /** The goods are partly back already: cancelling would put them on the shelf twice. */
    public static function invoiceHasReturns(string $number): self
    {
        return new self('invoice_has_returns', "Invoice {$number} has a return against it, so it cannot be cancelled.", [
            'number' => $number,
        ]);
    }

    /** Its return still credits the customer for the old invoice; cancelling it would break that pair. */
    public static function invoiceIsAnExchange(string $number): self
    {
        return new self('invoice_is_an_exchange', "Invoice {$number} is the new sale of an exchange, so it cannot be cancelled.", [
            'number' => $number,
        ]);
    }

    public static function outsideVoidWindow(string $number, int $windowHours): self
    {
        return new self('outside_void_window', "Invoice {$number} is past the {$windowHours}-hour cancellation window.", [
            'number' => $number,
            'window_hours' => $windowHours,
        ]);
    }

    /** Deleting it would leave its products with no category, without anyone being told. */
    public static function categoryHasProducts(string $name, int $count, int $archived = 0): self
    {
        // Archived products are on no screen, so the message has to say they are
        // the reason: otherwise the refusal looks like it has no cause.
        $message = $archived === 0
            ? "{$name} has {$count} products. Move them to another category before deleting it."
            : "{$name} has {$count} products, {$archived} of them archived. Archived products are not shown on screen, but they still belong to it. Restore them and move them to another category before deleting it.";

        return new self('category_has_products', $message, [
            'name' => $name,
            'products' => $count,
            'archived' => $archived,
        ]);
    }

    /** Deleting it would quietly promote its sub-categories to the top level. */
    public static function categoryHasChildren(string $name, int $count): self
    {
        return new self('category_has_children', "{$name} has {$count} sub-categories. Move or delete them first.", [
            'name' => $name,
            'children' => $count,
        ]);
    }

    /* ---------- shifts ---------- */

    public static function shiftAlreadyOpen(string $deviceCode, string $shiftId, ?string $openedBy): self
    {
        return new self('shift_already_open', "Till {$deviceCode} already has an open shift. Close it before opening another.", [
            'shift_id' => $shiftId,
            'opened_by' => $openedBy,
        ], 409);
    }

    public static function shiftAlreadyClosed(): self
    {
        return new self('shift_already_closed', 'This shift is already closed.', [], 409);
    }

    /** A seller counts their own drawer. A colleague's goes to a manager. */
    public static function shiftNotYours(): self
    {
        return new self('shift_not_yours', 'You can only close your own shift. Ask a branch manager to close this one.', [], 403);
    }

    public static function deviceInactive(string $code): self
    {
        return new self('device_inactive', "Till {$code} is switched off.", ['code' => $code]);
    }

    public static function shiftClosedBeforeItOpened(): self
    {
        return new self('shift_closed_before_opened', 'A shift cannot close before it opened.');
    }

    public static function shiftsHaveHistory(string $what, string $name): self
    {
        return new self('has_shift_history', "{$name} has shifts recorded against it. Switch it off instead of deleting it: a shift's count is the only record of that drawer.", [
            'what' => $what,
            'name' => $name,
        ]);
    }

    /* ---------- catalogue import ---------- */

    public const IMPORT_LIBRARY_COMMAND = 'docker exec scanzo-app composer require phpoffice/phpspreadsheet';

    /** Reading .xlsx needs a package that is not installed: say the command, never a 500. */
    public static function importLibraryMissing(): self
    {
        return new self(
            'import_library_missing',
            'Reading .xlsx files needs the phpoffice/phpspreadsheet package. Run: '.self::IMPORT_LIBRARY_COMMAND
                .' — or save the file as CSV UTF-8 and upload that instead.',
            ['command' => self::IMPORT_LIBRARY_COMMAND],
        );
    }

    public static function importUnsupportedFile(): self
    {
        return new self('import_unsupported_file', 'Upload an .xlsx or .csv file. Old .xls files are not supported: open it in Excel and save it as .xlsx.');
    }

    public static function importUnreadable(): self
    {
        return new self('import_unreadable', 'The file could not be read. It may be damaged or password protected.');
    }

    public static function importEmpty(): self
    {
        return new self('import_empty', 'The file has no product rows under the heading row.');
    }

    /**
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $found
     */
    public static function importMissingColumns(array $missing, array $found): self
    {
        return new self('import_missing_columns', 'The heading row is missing required columns: '.implode(', ', $missing).'.', [
            'missing' => $missing,
            'found' => $found,
        ]);
    }

    public static function importDuplicateColumn(string $column): self
    {
        return new self('import_duplicate_column', "The column {$column} appears twice in the heading row.", ['column' => $column]);
    }

    public static function importTooLarge(int $limit): self
    {
        return new self('import_too_large', "A file can have at most {$limit} rows. Split it and upload the parts one after the other.", [
            'limit' => $limit,
        ]);
    }

    public static function importInProgress(): self
    {
        return new self('import_in_progress', 'Another import for this shop is still running. Wait for it to finish.', [], 409);
    }

    /**
     * Nothing was saved. `errors` is every problem found, so the owner fixes
     * them all and uploads once, not one at a time.
     *
     * @param  array<int, array{row: int, field: string, message: string}>  $errors
     */
    public static function importRejected(array $errors, int $rows, int $shown): self
    {
        return new self('import_rejected', 'The file has errors, so nothing was imported.', [
            'errors' => array_slice($errors, 0, $shown),
            'error_count' => count($errors),
            'rows' => $rows,
        ]);
    }
}
