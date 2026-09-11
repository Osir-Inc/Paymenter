<?php

namespace App\Admin\Resources;

use App\Admin\Resources\RegistrarResource\Pages\CreateRegistrar;
use App\Admin\Resources\RegistrarResource\Pages\EditRegistrar;
use App\Admin\Resources\RegistrarResource\Pages\ListRegistrars;
use App\Helpers\ExtensionHelper;
use App\Models\Registrar;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class RegistrarResource extends Resource
{
    protected static ?string $model = Registrar::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->name;
    }

    public static function form(Schema $schema): Schema
    {
        $registrars = ExtensionHelper::getExtensions('registrar');

        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        static::getModel(),
                        'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule) => $rule->where('deleted_at', null)
                    )
                    ->placeholder('Enter the name of the registrar'),
                Select::make('extension')
                    ->label('Registrar')
                    ->required()
                    ->searchable()
                    ->options(array_combine(
                        array_column($registrars, 'name'),
                        array_column($registrars, 'name')
                    ))
                    ->live(onBlur: true)
                    ->disabledOn('edit')
                    ->afterStateUpdated(fn (Select $component) => $component
                        ->getContainer()
                        ->getComponent('settings')
                        ->getChildSchema()
                        ->fill())
                    ->placeholder('Select the registrar extension')
                    ->hintAction(
                        Action::make('Test Configuration')
                            ->action(function (Get $get, $record) {
                                // Dd settings
                                $connection = ExtensionHelper::testConfig($record, $get('settings'));

                                if ($connection === true) {
                                    Notification::make()
                                        ->title('Configuration is correct')
                                        ->success()->send();
                                } else {
                                    Notification::make()
                                        ->title('Connection failed: ' . $connection)
                                        ->danger()->send();
                                }
                            })
                            ->label('Test Connection')
                            ->hidden(function ($record) {
                                // If record is empty or textConfig is not available, then hide the button
                                return empty($record) || !ExtensionHelper::hasFunction($record, 'testConfig');
                            })
                    ),
                Section::make('Registrar Settings')
                    ->columnSpanFull()
                    ->description('Specific settings for the selected registrar')
                    ->schema([
                        Grid::make()->schema(fn (Get $get) => ExtensionHelper::getConfigAsInputs('registrar', $get('extension'), $get('settings')))->key('settings'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistrars::route('/'),
            'create' => CreateRegistrar::route('/create'),
            'edit' => EditRegistrar::route('/{record}/edit'),
        ];
    }
}
