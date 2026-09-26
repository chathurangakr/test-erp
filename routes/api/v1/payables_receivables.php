<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Finance\CustomerReceiptController;
use App\Http\Controllers\Api\V1\Finance\SalesInvoiceController;
use App\Http\Controllers\Api\V1\Finance\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\Finance\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

/*
 * Accounts payable and receivable. Each route checks the same permission as its web
 * counterpart (routes/finance.php) and uses the same route parameter names, so the shared
 * form requests resolve them identically. Payments and receipts have no update route — a
 * wrong draft is cancelled. No delete routes anywhere.
 */
Route::get('supplier-invoices', [SupplierInvoiceController::class, 'index'])->name('supplier-invoices.index')->can(Permission::ApView);
Route::post('supplier-invoices', [SupplierInvoiceController::class, 'store'])->name('supplier-invoices.store')->can(Permission::ApInvoiceCreate);
Route::get('supplier-invoices/{supplier_invoice}', [SupplierInvoiceController::class, 'show'])->name('supplier-invoices.show')->can(Permission::ApView);
Route::put('supplier-invoices/{supplier_invoice}', [SupplierInvoiceController::class, 'update'])->name('supplier-invoices.update')->can(Permission::ApInvoiceCreate);
Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->name('supplier-invoices.post')->can(Permission::ApInvoicePost);
Route::post('supplier-invoices/{supplier_invoice}/reverse', [SupplierInvoiceController::class, 'reverse'])->name('supplier-invoices.reverse')->can(Permission::ApInvoiceReverse);
Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel')->can(Permission::ApInvoiceCancel);

Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index')->can(Permission::ApView);
Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store')->can(Permission::ApPaymentCreate);
Route::get('supplier-payments/{supplier_payment}', [SupplierPaymentController::class, 'show'])->name('supplier-payments.show')->can(Permission::ApView);
Route::post('supplier-payments/{supplier_payment}/post', [SupplierPaymentController::class, 'post'])->name('supplier-payments.post')->can(Permission::ApPaymentPost);
Route::post('supplier-payments/{supplier_payment}/reverse', [SupplierPaymentController::class, 'reverse'])->name('supplier-payments.reverse')->can(Permission::ApPaymentReverse);
Route::post('supplier-payments/{supplier_payment}/cancel', [SupplierPaymentController::class, 'cancel'])->name('supplier-payments.cancel')->can(Permission::ApPaymentCancel);

Route::get('sales-invoices', [SalesInvoiceController::class, 'index'])->name('sales-invoices.index')->can(Permission::ArView);
Route::post('sales-invoices', [SalesInvoiceController::class, 'store'])->name('sales-invoices.store')->can(Permission::ArInvoiceCreate);
Route::get('sales-invoices/{sales_invoice}', [SalesInvoiceController::class, 'show'])->name('sales-invoices.show')->can(Permission::ArView);
Route::put('sales-invoices/{sales_invoice}', [SalesInvoiceController::class, 'update'])->name('sales-invoices.update')->can(Permission::ArInvoiceCreate);
Route::post('sales-invoices/{sales_invoice}/post', [SalesInvoiceController::class, 'post'])->name('sales-invoices.post')->can(Permission::ArInvoicePost);
Route::post('sales-invoices/{sales_invoice}/reverse', [SalesInvoiceController::class, 'reverse'])->name('sales-invoices.reverse')->can(Permission::ArInvoiceReverse);
Route::post('sales-invoices/{sales_invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->name('sales-invoices.cancel')->can(Permission::ArInvoiceCancel);

Route::get('customer-receipts', [CustomerReceiptController::class, 'index'])->name('customer-receipts.index')->can(Permission::ArView);
Route::post('customer-receipts', [CustomerReceiptController::class, 'store'])->name('customer-receipts.store')->can(Permission::ArReceiptCreate);
Route::get('customer-receipts/{customer_receipt}', [CustomerReceiptController::class, 'show'])->name('customer-receipts.show')->can(Permission::ArView);
Route::post('customer-receipts/{customer_receipt}/post', [CustomerReceiptController::class, 'post'])->name('customer-receipts.post')->can(Permission::ArReceiptPost);
Route::post('customer-receipts/{customer_receipt}/reverse', [CustomerReceiptController::class, 'reverse'])->name('customer-receipts.reverse')->can(Permission::ArReceiptReverse);
Route::post('customer-receipts/{customer_receipt}/cancel', [CustomerReceiptController::class, 'cancel'])->name('customer-receipts.cancel')->can(Permission::ArReceiptCancel);
