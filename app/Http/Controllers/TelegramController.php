<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TgUser;
use App\Models\Transaction;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramService $telegram
    ) {}

    /**
     * Entry-point for all incoming Telegram webhook updates.
     */
    public function handle(Request $request): Response
    {
        $update = $request->json()->all();

        // Guard: only process plain text messages
        $message = $update['message'] ?? null;
        if (! $message || ! isset($message['text'])) {
            return response('OK', 200);
        }

        $chatId    = (string) $message['chat']['id'];
        $text      = trim($message['text']);
        $from      = $message['from'] ?? [];

        try {
            // Auto-register user on first interaction
            $user = $this->resolveUser($chatId, $from);

            // Route to the correct command handler
            $this->route($chatId, $text, $user);
        } catch (Throwable $e) {
            Log::error('TelegramController error', ['exception' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, '⚠️ An internal error occurred. Please try again later.');
        }

        return response('OK', 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find or create the TgUser record for this chat_id.
     */
    private function resolveUser(string $chatId, array $from): TgUser
    {
        return TgUser::firstOrCreate(
            ['chat_id' => $chatId],
            [
                'first_name' => $from['first_name'] ?? null,
                'username'   => $from['username']   ?? null,
            ]
        );
    }

    /**
     * Parse the incoming text and dispatch to the right handler.
     */
    /**
     * Parse the incoming text and dispatch to the right handler safely.
     */
    private function route(string $chatId, string $text, TgUser $user): void
    {
        // /start
        if (preg_match('/^\/start$/i', $text)) {
            $this->handleStart($chatId, $user);
            return;
        }

        // /income <amount> [optional description]
        if (preg_match('/^\/income\s+(\d+(?:\.\d{1,2})?)\s*(.*)$/i', $text, $m)) {
            $amount = $m[1];
            $description = !empty($m[2]) ? trim($m[2]) : 'Uncategorized Income';
            $this->handleTransaction($chatId, $user, 'income', $amount, $description);
            return;
        }

        // /expense <amount> [optional description]
        if (preg_match('/^\/expense\s+(\d+(?:\.\d{1,2})?)\s*(.*)$/i', $text, $m)) {
            $amount = $m[1];
            $description = !empty($m[2]) ? trim($m[2]) : 'Uncategorized Expense';
            $this->handleTransaction($chatId, $user, 'expense', $amount, $description);
            return;
        }

        // /report
        if (preg_match('/^\/report$/i', $text)) {
            $this->handleReport($chatId, $user);
            return;
        }

        // Unknown input — show friendly syntax guide
        $this->handleUnknown($chatId);
    }

    /**
     * /start — welcome message with usage guide.
     */
    private function handleStart(string $chatId, TgUser $user): void
    {
        $name = $user->first_name ?? 'there';

        $msg = <<<MSG
        👋 *Hello, {$name}!* Welcome to your personal *Income & Expense Tracker Bot*.

        Here is everything I can do for you:

        ➕ *Log Income*
        `/income [amount] [description]`
        _Example:_ `/income 5000 Monthly salary`

        ➖ *Log Expense*
        `/expense [amount] [description]`
        _Example:_ `/expense 150 Grocery shopping`

        📊 *Daily Report*
        `/report`
        _Shows today's total income, expenses, and net balance._

        ---
        All amounts are treated as *BDT* by default.
        Let's start tracking! 💪
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    /**
     * /income or /expense — validate, persist, and confirm.
     */
    private function handleTransaction(
        string $chatId,
        TgUser $user,
        string $type,
        string $rawAmount,
        string $description
    ): void {
        $amount = (float) $rawAmount;

        if ($amount <= 0) {
            $this->telegram->sendMessage(
                $chatId,
                '⚠️ Amount must be greater than *0*. Please try again.'
            );
            return;
        }

        DB::transaction(function () use ($user, $type, $amount, $description): void {
            Transaction::create([
                'tg_user_id'  => $user->id,
                'type'        => $type,
                'amount'      => $amount,
                'description' => $description,
            ]);
        });

        if ($type === 'income') {
            $emoji  = '✅';
            $label  = 'Income';
            $symbol = '+';
        } else {
            $emoji  = '🔴';
            $label  = 'Expense';
            $symbol = '-';
        }

        $formatted = number_format($amount, 2);

        $msg = <<<MSG
        {$emoji} *{$label} Recorded*

        💰 Amount      : `{$symbol}{$formatted} BDT`
        📝 Description : {$description}
        🕒 Time        : {$this->nowFormatted()}
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    /**
     * /report — today's aggregated income, expense, and balance.
     */
    private function handleReport(string $chatId, TgUser $user): void
    {
        $today = Carbon::today();

        $rows = Transaction::query()
            ->where('tg_user_id', $user->id)
            ->whereDate('created_at', $today)
            ->selectRaw("type, SUM(amount) as total")
            ->groupBy('type')
            ->pluck('total', 'type');

        $income  = (float) ($rows['income']  ?? 0);
        $expense = (float) ($rows['expense'] ?? 0);
        $balance = $income - $expense;

        $balanceEmoji = $balance >= 0 ? '🟢' : '🔴';
        $balanceSign  = $balance >= 0 ? '+' : '';

        $msg = <<<MSG
        📊 *Daily Report — {$today->format('d M Y')}*
        ─────────────────────────
        ✅ *Total Income*  : `+{$this->fmt($income)} BDT`
        🔴 *Total Expense* : `-{$this->fmt($expense)} BDT`
        ─────────────────────────
        {$balanceEmoji} *Net Balance*  : `{$balanceSign}{$this->fmt($balance)} BDT`
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    /**
     * Fallback for unrecognised commands.
     */
    private function handleUnknown(string $chatId): void
    {
        $msg = <<<MSG
        🤔 *I didn't understand that command.*

        Here are the valid commands:

        `/income [amount] [description]`
        `/expense [amount] [description]`
        `/report`
        `/start`

        _Example:_ `/income 2000 Freelance payment`
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function fmt(float $value): string
    {
        return number_format(abs($value), 2);
    }

    private function nowFormatted(): string
    {
        return Carbon::now()->format('d M Y, h:i A');
    }
}
