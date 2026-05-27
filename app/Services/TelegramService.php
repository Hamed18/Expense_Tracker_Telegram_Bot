<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $baseUrl;

    public function __construct()
    {
        $this->token   = config('services.telegram.token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Send a Markdown-formatted message to a given chat ID.
     */
    public function sendMessage(string $chatId, string $text): void
    {
        $response = Http::post("{$this->baseUrl}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);

        if (! $response->successful()) {
            Log::error('Telegram sendMessage failed', [
                'chat_id' => $chatId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
        }
    }

    /**
     * Register the webhook URL with Telegram.
     * Call once via a console command or a setup route.
     */
    public function setWebhook(string $webhookUrl): bool
    {
        $response = Http::post("{$this->baseUrl}/setWebhook", [
            'url' => $webhookUrl,
        ]);

        return $response->successful();
    }
}