<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('customer', \App\Http\Controllers\Api\CustomerController::class);
Route::apiResource('detail_transaksi', \App\Http\Controllers\Api\DetailTransaksiController::class);
Route::apiResource('film', \App\Http\Controllers\Api\FilmController::class);
Route::apiResource('genre', \App\Http\Controllers\Api\GenreController::class);
Route::apiResource('jadwal', \App\Http\Controllers\Api\JadwalController::class);
Route::apiResource('kasir', \App\Http\Controllers\Api\KasirController::class);
Route::apiResource('komentar', \App\Http\Controllers\Api\KomentarController::class);
Route::apiResource('kursi', \App\Http\Controllers\Api\KursiController::class);
Route::apiResource('studio', \App\Http\Controllers\Api\StudioController::class);
Route::apiResource('tiket', \App\Http\Controllers\Api\TiketController::class);
Route::apiResource('transaksi', \App\Http\Controllers\Api\TransaksiController::class);
