<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TgUser extends Model
{
    protected $fillable = [
        'chat_id',
        'first_name',
        'username',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'tg_user_id');
    }
}