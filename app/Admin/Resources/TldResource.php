<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TldResource\Pages\CreateTld;
use App\Admin\Resources\TldResource\Pages\EditTld;
use App\Admin\Resources\TldResource\Pages\ListTlds;
use App\Models\Currency;
use App\Models\Tld;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TldResource extends Resource
{
    protected static ?string $model = Tld::class;

    protected static ?string $modelLabel = 'TLD';

    protected static ?string $pluralModelLabel = 'TLDs';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-price-tag-3-fill';

    protected static ?string $recordTitleAttribute = 'tld';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('TLD')
                    ->columns(3)
                    ->schema([
                        TextInput::make('tld')
                            ->label('TLD')
                            ->required()
                            ->maxLength(63)
                            ->prefix('.')
                            ->unique(ignoreRecord: true)
                            ->placeholder('com'),
                        Select::make('registrar_id')
                            ->label('Registrar')
                            ->relationship('registrar', 'name')
                            ->required()
                            ->preload(),
                        Grid::make(2)->schema([
                            TextInput::make('min_years')->label('Minimum term (years)')->numeric()->minValue(1)->maxValue(10)->default(1)->required(),
                            TextInput::make('max_years')->label('Maximum term (years)')->numeric()->minValue(1)->maxValue(10)->default(10)->required(),
                        ]),
                        Checkbox::make('enabled')->label('Enabled')->default(true),
                        Checkbox::make('featured')->label('Featured')->helperText('Checked in every domain search, next to the extension the customer typed'),
                        Checkbox::make('supports_transfer')->label('Transfers allowed')->default(true),
                    ]),
                Section::make('Prices')
                    ->description('Prices per term. Register, renew and transfer are the totals the customer pays for that number of years. Use the actions on this page to fill the grid from the registrar, to copy the 1 year prices to every term or to change all prices by a percentage.')
                    ->schema([
                        Repeater::make('prices')
                            ->relationship('prices')
                            ->hiddenLabel()
                            ->columns(5)
                            ->reorderable(false)
                            ->addActionLabel('Add price')
                            ->itemLabel(fn (array $state) => ($state['currency_code'] ?? '') . ' - ' . ($state['years'] ?? '') . ' year(s)')
                            ->collapsible()
                            ->schema([
                                Select::make('currency_code')
                                    ->label('Currency')
                                    ->options(fn () => Currency::pluck('code', 'code'))
                                    ->required(),
                                Select::make('years')
                                    ->label('Years')
                                    ->options(array_combine(range(1, 10), range(1, 10)))
                                    ->required(),
                                TextInput::make('register')->label('Register')->numeric()->minValue(0)->step(0.01)->prefix(fn (Get $get) => Currency::find($get('currency_code'))?->prefix),
                                TextInput::make('renew')->label('Renew')->numeric()->minValue(0)->step(0.01)->prefix(fn (Get $get) => Currency::find($get('currency_code'))?->prefix),
                                TextInput::make('transfer')->label('Transfer')->numeric()->minValue(0)->step(0.01)->prefix(fn (Get $get) => Currency::find($get('currency_code'))?->prefix),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $currency = config('settings.default_currency');

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('tld')->label('TLD')->formatStateUsing(fn ($state) => '.' . $state)->searchable()->sortable(),
                TextColumn::make('registrar.name')->label('Registrar')->sortable(),
                TextColumn::make('register_price')
                    ->label('Register (1y)')
                    ->state(fn (Tld $record) => $record->price($currency, 1, 'register'))
                    ->money($currency),
                TextColumn::make('renew_price')
                    ->label('Renew (1y)')
                    ->state(fn (Tld $record) => $record->price($currency, 1, 'renew'))
                    ->money($currency),
                IconColumn::make('enabled')->boolean(),
                IconColumn::make('featured')->boolean(),
            ])
            ->filters([
                SelectFilter::make('registrar')->relationship('registrar', 'name'),
                SelectFilter::make('enabled')->options(['1' => 'Enabled', '0' => 'Disabled']),
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

    public static function getPages(): array
    {
        return [
            'index' => ListTlds::route('/'),
            'create' => CreateTld::route('/create'),
            'edit' => EditTld::route('/{record}/edit'),
        ];
    }
}
