<?php

namespace App\Admin\Resources\TldResource\Pages;

use App\Admin\Resources\TldResource;
use App\Models\Tld;
use App\Services\Domain\ImportTldsService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTld extends EditRecord
{
    protected static string $resource = TldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('replicate')
                ->label('Copy 1 year prices to all terms')
                ->icon('ri-file-copy-line')
                ->modalDescription('Every term gets the 1 year price multiplied by the number of years, for each currency that has a 1 year price.')
                ->requiresConfirmation()
                ->action(function (Tld $record) {
                    $record->load('prices');
                    $count = 0;
                    foreach ($record->prices->where('years', 1) as $base) {
                        for ($years = 2; $years <= 10; $years++) {
                            $record->prices()->updateOrCreate(['currency_code' => $base->currency_code, 'years' => $years], [
                                'register' => $base->register === null ? null : round($base->register * $years, 2),
                                'renew' => $base->renew === null ? null : round($base->renew * $years, 2),
                                'transfer' => $base->transfer === null ? null : round($base->transfer * $years, 2),
                            ]);
                            $count++;
                        }
                    }
                    Notification::make()->title($count . ' prices updated')->success()->send();
                    $this->fillForm();
                }),
            Action::make('adjust')
                ->label('Change all prices by %')
                ->icon('ri-percent-line')
                ->schema([
                    TextInput::make('percent')->label('Change (%)')->helperText('Positive to increase, negative to discount, e.g. -10')->numeric()->required(),
                    Select::make('actions')->label('Apply to')->options(['register' => 'Register', 'renew' => 'Renew', 'transfer' => 'Transfer'])->multiple()->default(Tld::ACTIONS)->required(),
                ])
                ->action(function (array $data, Tld $record) {
                    $factor = 1 + ((float) $data['percent']) / 100;
                    foreach ($record->prices as $price) {
                        foreach ($data['actions'] as $action) {
                            if ($price->{$action} !== null) {
                                $price->{$action} = round($price->{$action} * $factor, 2);
                            }
                        }
                        $price->save();
                    }
                    Notification::make()->title('Prices updated')->success()->send();
                    $this->fillForm();
                }),
            Action::make('importPrices')
                ->label('Import prices from registrar')
                ->icon('ri-download-cloud-line')
                ->schema([
                    TextInput::make('markup')->label('Markup (%)')->numeric()->default(0)->required(),
                    Checkbox::make('overwrite')->label('Overwrite existing prices')->default(true),
                ])
                ->action(function (array $data, Tld $record) {
                    if (!$record->registrar) {
                        Notification::make()->title('No registrar assigned')->danger()->send();

                        return;
                    }
                    try {
                        $result = (new ImportTldsService)->handle($record->registrar, (float) $data['markup'], (bool) $data['overwrite'], $record);
                    } catch (Exception $e) {
                        Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title($result['imported'] ? 'Prices imported' : 'The registrar does not list this TLD')->status($result['imported'] ? 'success' : 'warning')->send();
                    $this->fillForm();
                }),
            DeleteAction::make(),
        ];
    }
}
