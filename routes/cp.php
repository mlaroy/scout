<?php

use Cascadia\Scout\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CP Scout
|--------------------------------------------------------------------------
|
| Loaded by Statamic inside its own CP route group (prefix, auth, and CP
| middleware are already applied). Per-action permissions are enforced
| in the controller.
|
*/

Route::prefix('scout')
    ->name('scout.')
    ->group(function () {
        Route::get('/', [AssistantController::class, 'page'])->name('index');
        Route::get('boot', [AssistantController::class, 'boot'])->name('boot');
        Route::post('preferences', [AssistantController::class, 'preferences'])->name('preferences');
        Route::post('chat', [AssistantController::class, 'chat'])->name('chat');
    });
