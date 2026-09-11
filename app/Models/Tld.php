<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tld extends Model
{
    use HasFactory;

    protected $fillable = [
        'tld',
        'registrar_id',
        'enabled',
        'featured',
        'supports_transfer',
        'min_years',
        'max_years',
        'sort',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'featured' => 'boolean',
        'supports_transfer' => 'boolean',
        'min_years' => 'integer',
        'max_years' => 'integer',
    ];

    public const ACTIONS = ['register', 'renew', 'transfer'];

    public static function normalize(string $tld): string
    {
        return ltrim(strtolower(trim($tld)), '.');
    }

    public function setTldAttribute($value): void
    {
        $this->attributes['tld'] = self::normalize($value);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Registrar::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TldPrice::class)->orderBy('currency_code')->orderBy('years');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Price from the grid for one action, currency and term, null when not configured
     */
    public function price(string $currency, int $years, string $action = 'register'): ?float
    {
        $row = $this->prices->where('currency_code', $currency)->where('years', $years)->first();
        $price = $row?->{$action};

        return $price === null ? null : (float) $price;
    }

    /**
     * Terms the customer can choose
     *
     * @return array<int>
     */
    public function terms(): array
    {
        return range(max(1, $this->min_years), max($this->min_years, min(10, $this->max_years)));
    }

    /**
     * Split "example.co.uk" into ['example', 'co.uk'] using the longest known TLD
     *
     * @return array{0: string, 1: ?Tld}
     */
    public static function split(string $domain): array
    {
        $domain = strtolower(trim($domain, ' .'));
        $parts = explode('.', $domain);
        for ($i = 1; $i < count($parts); $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            $tld = static::where('tld', $candidate)->first();
            if ($tld) {
                return [implode('.', array_slice($parts, 0, $i)), $tld];
            }
        }

        return [$domain, null];
    }
}
