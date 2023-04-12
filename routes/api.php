<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/auth/token', [TokenController::class, 'store']);
Route::middleware('auth:sanctum')->get('/users', [UserController::class, 'index']);
Route::middleware('auth:sanctum')->delete('/auth/token', [TokenController::class, 'destroy']);

Route::middleware('auth:sanctum')->post('/files', [FileController::class, 'store']);
Route::middleware('auth:sanctum')->get('/files', [FileController::class, 'index']);

Route::middleware('auth:sanctum')->get('/transactions', [TransactionController::class, 'index']);
Route::middleware('auth:sanctum')->get('/balances', [BalanceController::class, 'index']);
