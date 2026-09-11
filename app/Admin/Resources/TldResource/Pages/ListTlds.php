<?php

namespace App\Admin\Resources\TldResource\Pages;

use App\Admin\Resources\TldResource;
use App\Models\Registrar;
use App\Services\Domain\ImportTldsService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTlds extends ListRecords
{
    protected static string $resource = TldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import from registrar')
                ->icon('ri-download-cloud-line')
                ->modalDescription('Creates every TLD the registrar offers and fills the price grid for all terms from its per-year prices.')
                ->schema([
                    Select::make('registrar_id')
                        ->label('Registrar')
                        ->options(fn () => Registrar::pluck('name', 'id'))
                        ->required(),
                    TextInput::make('markup')->label('Markup (%)')->numeric()->default(0)->required(),
                    Checkbox::make('overwrite')->label('Overwrite existing prices')->default(true),
                ])
                ->action(function (array $data) {
                    try {
                        $result = (new ImportTldsService)->handle(Registrar::findOrFail($data['registrar_id']), (float) $data['markup'], (bool) $data['overwrite']);
                    } catch (Exception $e) {
                        Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('TLDs imported')
                        ->body($result['imported'] . ' TLDs imported' . ($result['skipped'] ? ', ' . $result['skipped'] . ' skipped (currency not configured in Paymenter).' : '.'))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
