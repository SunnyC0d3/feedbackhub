<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserDivision extends Pivot
{
    protected $table = 'user_divisions';

    protected $fillable = [
        'division_id',
        'user_id',
        'role',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
