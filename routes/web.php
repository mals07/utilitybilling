<?php

use App\Enums\Page;
use App\Http\Controllers\CompanyAssetController;
use App\Http\Controllers\CustomerPhotoController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MemoPrintController;
use App\Http\Controllers\ReceiptPrintController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\WorkOrderPhotoController;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\SignUp;
use App\Livewire\Auth\VerifyEmail;
use App\Livewire\Charges\ChargesPage;
use App\Livewire\Company\CompanyPage;
use App\Livewire\Customers\CustomersPage;
use App\Livewire\Dashboard;
use App\Livewire\Invoices\GenerateBillsPage;
use App\Livewire\Invoices\InvoicesPage;
use App\Livewire\MeterReadings\MeterReadingsPage;
use App\Livewire\Payments\PaymentsPage;
use App\Livewire\Rates\RatesPage;
use App\Livewire\Roles\RolesPage;
use App\Livewire\Users\UsersPage;
use App\Livewire\WorkOrders\WorkOrdersPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user()?->homeRoute() ?? 'login'));

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/signup', SignUp::class)->name('signup');
});

Route::livewire('/forgot-password', ForgotPassword::class)->name('password.request');
Route::livewire('/reset-password', ResetPassword::class)->name('password.reset');
Route::livewire('/accept-invitation', AcceptInvitation::class)->name('invitation.accept');

// Target of the emailed verification link.
Route::get('/auth/verify', VerifyEmailController::class)->name('auth.verify');

Route::middleware('auth')->group(function () {
    Route::livewire('/verify-email', VerifyEmail::class)->name('verify-email');
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/company-profile/{asset}', CompanyAssetController::class)
        ->whereIn('asset', ['logo', 'icon'])
        ->name('company.asset');
});

Route::middleware(['auth', 'verified.email'])->group(function () {
    Route::livewire('/company', CompanyPage::class)->middleware('page:'.Page::CompanySetup->value)->name('company');

    Route::middleware('company')->group(function () {
        Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
        Route::livewire('/customers', CustomersPage::class)->middleware('page:'.Page::Customers->value)->name('customers');
        Route::livewire('/work-orders', WorkOrdersPage::class)->middleware('page:'.Page::WorkOrders->value)->name('work-orders');
        Route::livewire('/meter-readings', MeterReadingsPage::class)->middleware('page:'.Page::MeterReadings->value)->name('meter-readings');
        Route::livewire('/invoices', InvoicesPage::class)->middleware('page:'.Page::Invoices->value)->name('invoices');
        Route::livewire('/invoices/generate', GenerateBillsPage::class)->middleware('page:'.Page::Invoices->value)->name('invoices.generate');
        Route::get('/invoices/{invoice}/print', InvoicePrintController::class)
            ->whereNumber('invoice')
            ->middleware('page:'.Page::Invoices->value)
            ->name('invoices.print');
        Route::get('/invoices/memos/{memo}/print', MemoPrintController::class)
            ->whereNumber('memo')
            ->middleware('page:'.Page::Invoices->value)
            ->name('invoices.memo');
        Route::livewire('/payments', PaymentsPage::class)->middleware('page:'.Page::Payments->value)->name('payments');
        Route::get('/payments/{payment}/receipt', ReceiptPrintController::class)
            ->whereNumber('payment')
            ->middleware('page:'.Page::Payments->value)
            ->name('payments.receipt');
        Route::get('/customers/{customer}/statement', StatementController::class)
            ->whereNumber('customer')
            ->name('customers.statement');
        Route::livewire('/rates', RatesPage::class)->middleware('page:'.Page::Rates->value)->name('rates');
        Route::livewire('/charges', ChargesPage::class)->middleware('page:'.Page::Charges->value)->name('charges');
        Route::livewire('/users', UsersPage::class)->middleware('page:'.Page::Users->value)->name('users');
        Route::livewire('/roles', RolesPage::class)->middleware('page:'.Page::Roles->value)->name('roles');

        Route::get('/customers/{customer}/photo', CustomerPhotoController::class)
            ->whereNumber('customer')
            ->middleware('page:'.Page::Customers->value)
            ->name('customers.photo');

        Route::get('/work-orders/photos/{photo}', WorkOrderPhotoController::class)
            ->whereNumber('photo')
            ->middleware('page:'.Page::WorkOrders->value)
            ->name('work-orders.photo');
    });
});
