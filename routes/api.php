<?php

use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ImportsController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ResolveTransactionController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\TransactionsController;
use Illuminate\Support\Facades\Route;

Route::post('/imports', [ImportsController::class, 'store']);
Route::get('/transactions', [TransactionsController::class, 'index']);
Route::get('/transactions/{id}', [TransactionsController::class, 'show']);
Route::post('/transactions/{id}/resolve', [ResolveTransactionController::class, 'store']);
