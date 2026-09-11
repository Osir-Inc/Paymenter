<?php

namespace App\Admin\Resources\RegistrarResource\Pages;

use App\Admin\Resources\RegistrarResource;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Services\Registrar\ImportTldPricingService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditRegistrar extends EditRecord
{
    protected static string $resource = RegistrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importTlds')
                ->label('Import TLDs')
                ->icon('ri-download-cloud-line')
                ->modalDescription('Creates or updates the "tld" config option of the selected product with one option per TLD, priced per year from the registrar pricing.')
                ->schema([
                    Select::make('product_id')
                        ->label('Domain product')
                        ->options(fn () => Product::where('registrar_id', $this->record->id)->pluck('name', 'id'))
                        ->required(),
                    TextInput::make('markup')
                        ->label('Markup (%)')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    TextInput::make('max_years')
                        ->label('Maximum term (years)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(10)
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $result = (new ImportTldPricingService)->handle(
                            $this->record,
                            Product::findOrFail($data['product_id']),
                            (float) $data['markup'],
                            (int) $data['max_years']
                        );
                    } catch (Exception $e) {
                        Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('TLDs imported')
                        ->body($result['imported'] . ' TLDs imported' . ($result['skipped'] ? ', ' . $result['skipped'] . ' skipped because their currency does not exist in Paymenter.' : '.'))
                        ->success()
                        ->send();
                }),
            DeleteAction::make()->before(fn ($record) => ExtensionHelper::call($record, 'disabled', [$record], mayFail: true)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->settings as $setting) {
            $data['settings'][$setting->key] = $setting->value;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        $config = ExtensionHelper::getConfig($record->type, $record->extension);

        foreach ($config as $option) {
            $record->settings()->updateOrCreate([
                'key' => $option['name'],
                'settingable_id' => $record->id,
                'settingable_type' => $record->getMorphClass(),
            ], [
                'type' => $option['database_type'] ?? 'string',
                'value' => isset($data['settings'][$option['name']]) ? (is_array($data['settings'][$option['name']]) ? json_encode($data['settings'][$option['name']]) : $data['settings'][$option['name']]) : null,
                'encrypted' => $option['encrypted'] ?? false,
            ]);
        }

        ExtensionHelper::call($record, 'updated', [$record], mayFail: true);

        // Maybe the extension changed the record, so we need to refresh it
        return $record->refresh();
    }
}
