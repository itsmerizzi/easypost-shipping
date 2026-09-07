<?php

use Illuminate\Support\Facades\Route;

// Everything that is not the API, Sanctum or the health check is the React app.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
