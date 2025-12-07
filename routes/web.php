<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;

Route::get('/poster/{filename}', function ($filename) {
    $path = public_path('poster/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return Response::file($path);
})
    ->where('filename', '.*')
    ->middleware('poster.cors');