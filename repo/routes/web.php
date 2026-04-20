<?php

use Illuminate\Support\Facades\Route;

Route::post('/login', function () {
    return response()->json(['message' => 'Unauthorized'], 401);
});

// All non-API traffic serves the Vue SPA; Vue Router handles client-side routing.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|up|storage|build).*$');
