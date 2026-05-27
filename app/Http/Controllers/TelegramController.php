<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TgUser;
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
     * Entry point for all incoming Telegram webhook payloads.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();

        if (empty($payload['message'])) {
            return response('OK', 200);
        }

        $message = $payload['message'];
        $from    = $message['from'] ?? [];
        $chatId  = (string) ($message['chat']['id'] ?? '');
        $text    = trim($message['text'] ?? '');

        if ($chatId === '' || $text === '') {
            return response('OK', 200);
        }

        try {
            $user = TgUser::firstOrCreate(
                ['chat_id' => $chatId],
                [
                    'first_name' => $from['first_name'] ?? null,
                    'username'   => $from['username']   ?? null,
                ]
            );

            $this->dispatch($user, $chatId, $text);
        } catch (Throwable $e) {
            Log::error('Telegram webhook error', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            $this->telegram->sendMessage(
                $chatId,
                '⚠️ An unexpected error occurred. Please try again.'
            );
        }

        return response('OK', 200);
    }

    // ---------------------------------------------------------------
    //  Command Router
    // ---------------------------------------------------------------

    private function dispatch(TgUser $user, string $chatId, string $text): void
    {
        // /start
        if (preg_match('/^\/start$/i', $text)) {
            $this->handleStart($chatId, $user);
            return;
        }

        // /income <amount> [optional description]
        if (preg_match('/^\/income\s+(\d+(?:\.\d{1,2})?)\s*(.*)$/i', $text, $matches)) {
            $description = trim($matches[2]) !== '' ? trim($matches[2]) : 'Uncategorized Income';
            $this->handleTransaction($user, $chatId, 'income', $matches[1], $description);
            return;
        }

        // /expense <amount> [optional description]
        if (preg_match('/^\/expense\s+(\d+(?:\.\d{1,2})?)\s*(.*)$/i', $text, $matches)) {
            $description = trim($matches[2]) !== '' ? trim($matches[2]) : 'Uncategorized Expense';
            $this->handleTransaction($user, $chatId, 'expense', $matches[1], $description);
            return;
        }

        // /report monthly
        if (preg_match('/^\/report\s+monthly$/i', $text)) {
            $this->handleMonthlyReport($user, $chatId);
            return;
        }

        // /report (daily)
        if (preg_match('/^\/report$/i', $text)) {
            $this->handleReport($user, $chatId);
            return;
        }

        // /reset
        if (preg_match('/^\/reset$/i', $text)) {
            $this->handleReset($user, $chatId);
            return;
        }

        // Unknown / malformed input
        $this->handleUnknown($chatId);
    }

    // ---------------------------------------------------------------
    //  Handlers
    // ---------------------------------------------------------------

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

        📅 *Monthly Report*
        `/report monthly`

        🗑 *Reset Data*
        `/reset`

        ---
        All amounts are treated as *BDT* by default.
        Let's start tracking! 💪
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function handleTransaction(
        TgUser $user,
        string $chatId,
        string $type,
        string $rawAmount,
        string $description
    ): void {
        $amount = (float) $rawAmount;

        if ($amount <= 0) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ *Invalid amount.* Please enter a positive number greater than zero.'
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

        $formatted = number_format($amount, 2);

        if ($type === 'income') {
            $msg = <<<MSG
            ✅ *Income Recorded*

            💰 Amount      : `+{$formatted} BDT`
            📝 Description : {$description}
            🕒 Time        : {$this->nowFormatted()}
            MSG;
        } else {
            $msg = <<<MSG
            🔴 *Expense Recorded*

            💰 Amount      : `-{$formatted} BDT`
            📝 Description : {$description}
            🕒 Time        : {$this->nowFormatted()}
            MSG;
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function handleReport(TgUser $user, string $chatId): void
    {
        $today = Carbon::today();

        $totals = Transaction::query()
            ->where('tg_user_id', $user->id)
            ->whereDate('created_at', $today)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
            ")
            ->first();

        $income  = (float) $totals->total_income;
        $expense = (float) $totals->total_expense;
        $balance = $income - $expense;

        $balanceIcon = $balance >= 0 ? '🟢' : '🔴';
        $balanceSign = $balance >= 0 ? '+' : '-';

        $msg = <<<MSG
        📊 *Daily Report — {$today->format('d M Y')}*
        ─────────────────────────
        ✅ *Total Income*  : `+{$this->fmt($income)} BDT`
        🔴 *Total Expense* : `-{$this->fmt($expense)} BDT`
        ─────────────────────────
        {$balanceIcon} *Net Balance*  : `{$balanceSign}{$this->fmt($balance)} BDT`
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function handleMonthlyReport(TgUser $user, string $chatId): void
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();
        $monthLabel = $now->format('F Y');

        $totals = Transaction::query()
            ->where('tg_user_id', $user->id)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense,
                COUNT(*) AS total_transactions
            ")
            ->first();

        $income  = (float) ($totals->total_income  ?? 0);
        $expense = (float) ($totals->total_expense ?? 0);
        $txCount = (int)   ($totals->total_transactions ?? 0);
        $balance = $income - $expense;

        $balanceIcon = $balance >= 0 ? '🟢' : '🔴';
        $balanceSign = $balance >= 0 ? '+' : '-';

        $msg = <<<MSG
        📅 *Monthly Summary — {$monthLabel}*
        ─────────────────────────
        ✅ *Total Income*    : `+{$this->fmt($income)} BDT`
        🔴 *Total Expenses*  : `-{$this->fmt($expense)} BDT`
        ─────────────────────────
        {$balanceIcon} *Net Balance*    : `{$balanceSign}{$this->fmt($balance)} BDT`
        🔢 *Total Logs*     : `{$txCount} entries`
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function handleReset(TgUser $user, string $chatId): void
    {
        $deleted = DB::transaction(function () use ($user): int {
            return Transaction::where('tg_user_id', $user->id)->delete();
        });

        $msg = <<<MSG
        🗑 *Reset Complete!*

        All *{$deleted}* of your database logs have been permanently dropped.
        Your accounting ledger balance is back to *0.00 BDT*.
        MSG;

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function handleUnknown(string $chatId): void
    {
        $msg = <<<MSG
        🤔 *I didn't understand that command.*

        Here are the valid commands:

        `/income [amount] [description]`
        `/expense [amount] [description]`
        `/report`
        `/report monthly`
        `/reset`
        `/start`
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