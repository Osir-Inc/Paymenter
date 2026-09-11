<?php

namespace App\Models;

use App\Classes\Price;
use App\Models\Traits\HasProperties;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Contracts\Auditable;

class Domain extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_TRANSFER = 'pending_transfer';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TRANSFERRED_AWAY = 'transferred_away';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_PENDING_TRANSFER,
        self::STATUS_EXPIRED,
        self::STATUS_TRANSFERRED_AWAY,
        self::STATUS_CANCELLED,
    ];

    public const ACTION_REGISTER = 'register';

    public const ACTION_TRANSFER = 'transfer';

    protected $fillable = [
        'user_id',
        'order_id',
        'tld_id',
        'registrar_id',
        'name',
        'domain',
        'status',
        'action',
        'years',
        'price',
        'currency_code',
        'auth_code',
        'auto_renew',
        'privacy',
        'nameservers',
        'registered_at',
        'expires_at',
    ];

    protected $casts = [
        'years' => 'integer',
        'auto_renew' => 'boolean',
        'privacy' => 'boolean',
        'nameservers' => 'array',
        'auth_code' => 'encrypted',
        'registered_at' => 'datetime',
        'expires_at' => 'date',
    ];

    protected $auditInclude = [
        'status',
        'expires_at',
        'auto_renew',
        'nameservers',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Registrar::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'reference_id', 'id', 'id', 'invoice_id')->where('reference_type', self::class);
    }

    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price, 'currency' => $this->currency])
        );
    }

    /**
     * Description used on invoice items
     */
    public function description(string $action = 'renew'): string
    {
        return __('domains.invoice_description.' . $action, ['domain' => $this->domain, 'years' => $this->years]) . ' (' . trans_choice(__('domains.years'), $this->years, ['count' => $this->years]) . ')';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isManageable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_EXPIRED]);
    }
}
