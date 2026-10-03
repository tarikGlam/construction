<?php

use Illuminate\Support\Facades\Route;
use Modules\VCardNfc\Http\Controllers\NfcCardController;
use Modules\VCardNfc\Http\Controllers\PublicVCardController;
use Modules\VCardNfc\Http\Controllers\VCardProfileController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

$isSaaS = (bool) config('database.connections.saleprosaas_landlord');
$tenancyMiddleware = $isSaaS ? [InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class] : [];

Route::middleware(array_merge($tenancyMiddleware, ['module.enabled:vcardnfc']))->group(function () {
    Route::get('/vcard/{slug}', [PublicVCardController::class, 'show'])->name('vcardnfc.public.show');
    Route::get('/vcard/{slug}/contact.vcf', [PublicVCardController::class, 'vcf'])->name('vcardnfc.public.vcf');
    Route::get('/vcard/{slug}/qr.svg', [PublicVCardController::class, 'qr'])->name('vcardnfc.public.qr');
    Route::get('/n/{token}', [PublicVCardController::class, 'nfc'])->name('vcardnfc.public.nfc');
});

$adminMiddleware = array_merge($tenancyMiddleware, ['common', 'auth', 'active', 'module.access:vcardnfc']);

Route::prefix('vcard-nfc')->name('vcardnfc.')->middleware($adminMiddleware)->group(function () {
    Route::get('people/search', [VCardProfileController::class, 'personSearch'])->name('people.search');
    Route::get('people/details', [VCardProfileController::class, 'personDetails'])->name('people.details');
    Route::get('profiles/check-slug', [VCardProfileController::class, 'checkSlug'])->name('profiles.check-slug');

    Route::resource('profiles', VCardProfileController::class)->except(['show']);
    Route::get('nfc', [NfcCardController::class, 'index'])->name('nfc.index');
    Route::post('nfc', [NfcCardController::class, 'store'])->name('nfc.store');
    Route::put('nfc/{card}', [NfcCardController::class, 'update'])->name('nfc.update');
    Route::delete('nfc/{card}', [NfcCardController::class, 'destroy'])->name('nfc.destroy');
});
