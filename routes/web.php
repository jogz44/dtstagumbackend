<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Mobile Scanner Page – served as a standalone HTML page.
 * The phone opens this URL after scanning the QR code.
 * The token is passed as a query parameter and used throughout.
 */
Route::get('/scan', function () {
    return view('scan');
});
