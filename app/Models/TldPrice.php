<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TldPrice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tld_id',
        'currency_code',
        'years',
        'register',
        'renew',
        'transfer',
    ];

    protected $casts = [
        'years' => 'integer',
    ];

    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
