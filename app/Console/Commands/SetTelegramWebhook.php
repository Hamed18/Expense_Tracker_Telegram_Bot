<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SetTelegramWebhook extends Command
{
    protected $signature   = 'telegram:set-webhook {url}';
    protected $description = 'Register the Telegram webhook URL with the Bot API';

    public function handle(TelegramService $telegram): int
    {
        $url = $this->argument('url');
        $ok  = $telegram->setWebhook($url);

        if ($ok) {
            $this->info("✅ Webhook set successfully: {$url}");
            return self::SUCCESS;
        }

        $this->error('❌ Failed to set webhook. Check your token and URL.');
        return self::FAILURE;
    }
}