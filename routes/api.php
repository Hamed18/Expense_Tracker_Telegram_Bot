<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

// Telegram webhook — receives POST updates from Telegram servers
Route::post('/telegram/webhook', [TelegramController::class, 'handle']);