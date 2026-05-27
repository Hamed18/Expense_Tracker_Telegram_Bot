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

    // ---------------------------------------------------------------
    //  Webhook Entry Point
    // ---------------------------------------------------------------

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
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->telegram->sendMessage(
                $chatId,
                '⚠️ An unexpected error occurred\. Please try again\.'
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
        // Description is fully optional — \s*(.*)$ prevents "Undefined array key 2"
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

        // ⚠️  /report monthly MUST be matched BEFORE plain /report
        // to prevent the shorter pattern swallowing the full command
        if (preg_match('/^\/report\s+monthly$/i', $text)) {
            $this->handleMonthlyReport($user, $chatId);
            return;
        }

        // /report  (daily)
        if (preg_match('/^\/report$/i', $text)) {
            $this->handleDailyReport($user, $chatId);
            return;
        }

        // /reset
        if (preg_match('/^\/reset$/i', $text)) {
            $this->handleReset($user, $chatId);
            return;
        }

        // Fallback
        $this->handleUnknown($chatId);
    }

    // ---------------------------------------------------------------
    //  /start
    // ---------------------------------------------------------------

    private function handleStart(string $chatId, TgUser $user): void
    {
        $name = $user->first_name ?? 'there';

        $msg = implode("\n", [
            "👋 *Hello, {$name}\!*",
            "",
            "Welcome to your personal *Income & Expense Tracker Bot*\.",
            "Here's what you can do:",
            "",
            "➕ *Log Income*",
            "`/income [amount] [description]`",
            "_Example:_ `/income 500 Freelance payment`",
            "",
            "➖ *Log Expense*",
            "`/expense [amount] [description]`",
            "_Example:_ `/expense 120 Grocery shopping`",
            "",
            "📊 *Today's Detailed Report*",
            "`/report`",
            "",
            "📅 *Monthly Day\-by\-Day Report*",
            "`/report monthly`",
            "",
            "🗑 *Reset All Transactions*",
            "`/reset`",
            "",
            "Let's start tracking\! 💰",
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  /income  &  /expense
    // ---------------------------------------------------------------

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
                '❌ *Invalid amount\.* Please enter a positive number greater than zero\.'
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

        $formatted = number_format($amount, 2) . ' BDT';
        $timeLabel = Carbon::now()->format('h:i A');

        if ($type === 'income') {
            $sign  = '+';
            $icon  = '💚';
            $label = 'Income';
        } else {
            $sign  = '-';
            $icon  = '🔴';
            $label = 'Expense';
        }

        $escapedDesc = $this->escape($description);

        $msg = implode("\n", [
            "✅ *{$label} Logged\!*",
            "",
            "{$icon} *Amount:* {$sign}{$formatted}",
            "📝 *Description:* {$escapedDesc}",
            "🕐 *Time:* {$timeLabel}",
            "📆 *Date:* " . $this->escape(Carbon::today()->toFormattedDateString()),
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  /report  —  Daily detailed line-by-line report
    // ---------------------------------------------------------------

    private function handleDailyReport(TgUser $user, string $chatId): void
    {
        $today = Carbon::today();

        /** @var \Illuminate\Support\Collection $transactions */
        $transactions = Transaction::query()
            ->where('tg_user_id', $user->id)
            ->whereDate('created_at', $today)
            ->orderBy('created_at', 'asc')
            ->get(['type', 'amount', 'description', 'created_at']);

        if ($transactions->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "📊 *Daily Report — " . $this->escape($today->toFormattedDateString()) . "*\n\n"
                . "_No transactions logged today\._"
            );
            return;
        }

        $totalIncome  = 0.0;
        $totalExpense = 0.0;
        $lines        = [];

        foreach ($transactions as $tx) {
            $time        = Carbon::parse($tx->created_at)->format('h:i A');
            $amount      = (float) $tx->amount;
            $isIncome    = $tx->type === 'income';
            $sign        = $isIncome ? '+' : '-';
            $typeLabel   = $isIncome ? '💚 Income' : '🔴 Expense';
            $formatted   = number_format($amount, 2) . ' BDT';
            $escapedDesc = $this->escape($tx->description);

            // • [10:30 AM] 💚 Income  +120.00 BDT - Grocery
            $lines[] = "• \[{$time}\] {$typeLabel}  {$sign}{$formatted} \- {$escapedDesc}";

            if ($isIncome) {
                $totalIncome += $amount;
            } else {
                $totalExpense += $amount;
            }
        }

        $balance     = $totalIncome - $totalExpense;
        $balanceIcon = $balance >= 0 ? '💚' : '🔴';
        $balanceSign = $balance >= 0 ? '+' : '-';
        $dateLabel   = $this->escape($today->toFormattedDateString());

        $msg = implode("\n", array_merge(
            [
                "📊 *Daily Report — {$dateLabel}*",
                "",
            ],
            $lines,
            [
                "",
                "────────────────────────",
                "➕ *Total Income:*   " . number_format($totalIncome,  2) . " BDT",
                "➖ *Total Expenses:* " . number_format($totalExpense, 2) . " BDT",
                "────────────────────────",
                "{$balanceIcon} *Net Balance:*   {$balanceSign}" . number_format(abs($balance), 2) . " BDT",
            ]
        ));

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  /report monthly  —  Day-by-day nested breakdown
    // ---------------------------------------------------------------

    private function handleMonthlyReport(TgUser $user, string $chatId): void
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();
        $monthLabel = $this->escape($now->format('F Y')); // e.g. May 2026

        /** @var \Illuminate\Support\Collection $transactions */
        $transactions = Transaction::query()
            ->where('tg_user_id', $user->id)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->orderBy('created_at', 'asc')
            ->get(['type', 'amount', 'description', 'created_at']);

        if ($transactions->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "📅 *Monthly Report — {$monthLabel}*\n\n"
                . "_No transactions logged for this month\._"
            );
            return;
        }

        // Group by calendar date string e.g. "2026-05-27"
        $grouped = $transactions->groupBy(
            fn ($tx) => Carbon::parse($tx->created_at)->toDateString()
        );

        $grandIncome   = 0.0;
        $grandExpense  = 0.0;
        $totalTxCount  = 0;
        $sections      = [];

        foreach ($grouped as $dateString => $dayTransactions) {
            $dayLabel    = $this->escape(Carbon::parse($dateString)->format('F j, Y'));
            $dayIncome   = 0.0;
            $dayExpense  = 0.0;
            $dayLines    = ["📅 *{$dayLabel}*"];

            foreach ($dayTransactions as $tx) {
                $time        = Carbon::parse($tx->created_at)->format('h:i A');
                $amount      = (float) $tx->amount;
                $isIncome    = $tx->type === 'income';
                $sign        = $isIncome ? '+' : '-';
                $typeLabel   = $isIncome ? '💚 Income' : '🔴 Expense';
                $formatted   = number_format($amount, 2) . ' BDT';
                $escapedDesc = $this->escape($tx->description);

                $dayLines[] = "• \[{$time}\] {$typeLabel}  {$sign}{$formatted} \- {$escapedDesc}";

                if ($isIncome) {
                    $dayIncome += $amount;
                } else {
                    $dayExpense += $amount;
                }

                $totalTxCount++;
            }

            // Per-day mini summary
            $dayBalance     = $dayIncome - $dayExpense;
            $dayBalIcon     = $dayBalance >= 0 ? '💚' : '🔴';
            $dayBalSign     = $dayBalance >= 0 ? '+' : '-';
            $dayLines[]     = "_Day total: {$dayBalIcon} {$dayBalSign}" . number_format(abs($dayBalance), 2) . " BDT_";

            $grandIncome  += $dayIncome;
            $grandExpense += $dayExpense;

            $sections[] = implode("\n", $dayLines);
        }

        $grandBalance     = $grandIncome - $grandExpense;
        $grandBalanceIcon = $grandBalance >= 0 ? '💚' : '🔴';
        $grandBalanceSign = $grandBalance >= 0 ? '+' : '-';

        $footer = implode("\n", [
            "════════════════════════",
            "📅 *Grand Total — {$monthLabel}*",
            "════════════════════════",
            "➕ *Total Income:*      " . number_format($grandIncome,       2) . " BDT",
            "➖ *Total Expenses:*    " . number_format($grandExpense,      2) . " BDT",
            "────────────────────────",
            "{$grandBalanceIcon} *Net Balance:*      {$grandBalanceSign}" . number_format(abs($grandBalance), 2) . " BDT",
            "🔢 *Total Transactions:* {$totalTxCount}",
        ]);

        // Stitch header + all day sections + footer
        $msg = implode("\n\n", array_merge(
            ["📅 *Monthly Report — {$monthLabel}*"],
            $sections,
            [$footer]
        ));

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  /reset
    // ---------------------------------------------------------------

    private function handleReset(TgUser $user, string $chatId): void
    {
        $deleted = DB::transaction(
            fn (): int => Transaction::where('tg_user_id', $user->id)->delete()
        );

        $msg = implode("\n", [
            "🗑 *Reset Complete\!*",
            "",
            "All *{$deleted}* of your transaction records have been permanently deleted\.",
            "Your balance is now back to *0\.00 BDT*\.",
            "",
            "Ready to start fresh? Use `/income` or `/expense` to begin tracking again\.",
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  Fallback
    // ---------------------------------------------------------------

    private function handleUnknown(string $chatId): void
    {
        $msg = implode("\n", [
            "🤔 *I didn't understand that\.*",
            "",
            "Here are the supported commands:",
            "",
            "`/start`                               — Welcome message & guide",
            "`/income [amount] [description]`       — Log income",
            "`/expense [amount] [description]`      — Log expense",
            "`/report`                              — Today's detailed report",
            "`/report monthly`                      — This month's day\-by\-day report",
            "`/reset`                               — Delete all your transactions",
            "",
            "_Example:_ `/income 250 Salary advance`",
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    /**
     * Today's date as a formatted string, e.g. "May 27, 2026".
     */
    private function today(): string
    {
        return Carbon::today()->toFormattedDateString();
    }

    /**
     * Escape all MarkdownV2 reserved characters so Telegram
     * does not misparse them as formatting tokens.
     *
     * Reserved chars: _ * [ ] ( ) ~ ` > # + - = | { } . !
     */
    private function escape(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!])/', '\\\\$1', $text);
    }
}