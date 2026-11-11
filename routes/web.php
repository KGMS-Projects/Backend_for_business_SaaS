<?php

use Illuminate\Support\Facades\Route;

// This service is API-only. The root URL just tells a curious visitor where to look.
Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'api' => url('/api'),
    'docs' => 'See README.md and docs/openapi.yaml',
]));
