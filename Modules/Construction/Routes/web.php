<?php
use Illuminate\Support\Facades\Route;
use Modules\Construction\Http\Controllers\DashboardController;
use Modules\Construction\Http\Controllers\OperationsController;
use Modules\Construction\Http\Controllers\ReportController;

Route::middleware(['common','auth','active','warehouse.access','module.access:construction'])->prefix('construction')->name('construction.')->group(function(){
 Route::get('dashboard',DashboardController::class)->middleware('permission:construction.dashboard.view')->name('dashboard');
 Route::get('clients',[OperationsController::class,'clients'])->name('clients'); Route::post('clients',[OperationsController::class,'storeClient'])->name('clients.store');
 Route::get('material-catalog',[OperationsController::class,'materialCatalog'])->name('catalog'); Route::post('material-catalog',[OperationsController::class,'storeMaterial'])->name('catalog.store');
 Route::middleware('permission:construction.project-costs.manage')->group(function(){Route::get('project-costs',[OperationsController::class,'costs'])->name('costs');Route::post('project-costs',[OperationsController::class,'storeCost'])->name('costs.store');});
 Route::get('materials',[OperationsController::class,'materials'])->middleware('permission:construction.material-issues.manage')->name('materials'); Route::post('material-issues',[OperationsController::class,'storeIssue'])->middleware('permission:construction.material-issues.manage')->name('issues.store'); Route::post('material-issues/{issue}/returns',[OperationsController::class,'storeReturn'])->middleware('permission:construction.material-returns.manage')->name('returns.store');
 Route::middleware('permission:construction.subcontractors.manage')->group(function(){Route::get('subcontractors',[OperationsController::class,'subcontractors'])->name('subcontractors');Route::post('subcontractors',[OperationsController::class,'storeSubcontractor'])->name('subcontractors.store');Route::post('subcontractor-contracts',[OperationsController::class,'storeContract'])->name('contracts.store');});
 Route::middleware('permission:construction.project-wages.manage')->group(function(){Route::get('workforce',[OperationsController::class,'workforce'])->name('workforce');Route::post('project-wages',[OperationsController::class,'storeWage'])->name('wages.store');});
 Route::middleware('permission:construction.equipment.manage')->group(function(){Route::get('equipment',[OperationsController::class,'equipment'])->name('equipment');Route::post('equipment',[OperationsController::class,'storeEquipment'])->name('equipment.store');Route::post('equipment-assignments',[OperationsController::class,'storeAssignment'])->name('assignments.store');});
 Route::middleware('permission:construction.project-receipts.manage')->group(function(){Route::get('project-receipts',[OperationsController::class,'receipts'])->name('receipts');Route::post('project-receipts',[OperationsController::class,'storeReceipt'])->name('receipts.store');});
 Route::middleware('permission:construction.reports.view')->group(function(){Route::get('reports/project-profitability',[ReportController::class,'profitability'])->name('reports.profitability');Route::get('reports/project-statement',[ReportController::class,'statement'])->name('reports.statement');});
 Route::get('settings',[OperationsController::class,'settings'])->name('settings');
});
