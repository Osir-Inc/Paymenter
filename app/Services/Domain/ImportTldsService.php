<?php

namespace App\Services\Domain;

use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Registrar;
use App\Models\Tld;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates TLDs and their price grid from a registrar's price list.
 * The registrar returns one base price per year; every term gets base × years × (1 + markup).
 */
class ImportTldsService
{
    /**
     * @param  Tld|null  $only  Import only this TLD (used by the "Import prices" action on a TLD)
     * @return array{imported: int, skipped: int}
     */
    public function handle(Registrar $registrar, float $markup = 0, bool $overwrite = true, ?Tld $only = null): array
    {
        $pricing = ExtensionHelper::callRegistrar($registrar, 'getTldPricing');
        $currencies = Currency::pluck('code')->map(fn ($code) => strtoupper($code))->all();

        return DB::transaction(function () use ($pricing, $currencies, $registrar, $markup, $overwrite, $only) {
            $imported = 0;
            $skipped = 0;
            $sort = Tld::max('sort') ?? 0;

            foreach ($pricing as $tld => $data) {
                $tld = Tld::normalize($tld);
                $currency = strtoupper($data['currency'] ?? '');
                if (!$tld || !in_array($currency, $currencies) || ($only && $only->tld !== $tld)) {
                    $skipped++;

                    continue;
                }

                $model = Tld::firstOrNew(['tld' => $tld]);
                if (!$model->exists) {
                    $model->fill(['registrar_id' => $registrar->id, 'enabled' => true, 'sort' => ++$sort]);
                }
                $model->fill([
                    'min_years' => max(1, (int) ($data['min_years'] ?? 1)),
                    'max_years' => min(10, max(1, (int) ($data['max_years'] ?? 10))),
                ]);
                $model->save();

                for ($years = 1; $years <= 10; $years++) {
                    $row = $model->prices()->firstOrNew(['currency_code' => $currency, 'years' => $years]);
                    if ($row->exists && !$overwrite) {
                        continue;
                    }
                    $row->fill([
                        'register' => $this->term($data['register'] ?? null, $years, $markup),
                        'renew' => $this->term($data['renew'] ?? null, $years, $markup),
                        'transfer' => $this->term($data['transfer'] ?? null, $years, $markup),
                    ])->save();
                }
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    private function term($base, int $years, float $markup): ?float
    {
        return $base === null ? null : round((float) $base * $years * (1 + $markup / 100), 2);
    }
}
