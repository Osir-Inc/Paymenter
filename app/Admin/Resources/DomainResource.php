<?php

namespace App\Admin\Resources;

use App\Admin\Resources\DomainResource\Pages\CreateDomain;
use App\Admin\Resources\DomainResource\Pages\EditDomain;
use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Models\Domain;
use App\Models\Tld;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static ?string $recordTitleAttribute = 'domain';

    public static function getGloballySearchableAttributes(): array
    {
        return ['domain'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->domain;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Domain')
                    ->columns(3)
                    ->schema([
                        Select::make('user_id')
                            ->label('Customer')
                            ->relationship('user', 'email')
                            ->searchable(['first_name', 'last_name', 'email'])
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->name . ' (' . $record->email . ')')
                            ->required(),
                        TextInput::make('name')
                            ->label('Name')
                            ->helperText('Without the extension, e.g. example')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setDomain($get, $set)),
                        Select::make('tld_id')
                            ->label('TLD')
                            ->relationship('tld', 'tld')
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => '.' . $record->tld)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                self::setDomain($get, $set);
                                $set('registrar_id', Tld::find($get('tld_id'))?->registrar_id);
                            }),
                        TextInput::make('domain')->label('Full domain')->disabled()->dehydrated(),
                        Select::make('registrar_id')
                            ->label('Registrar')
                            ->relationship('registrar', 'name')
                            ->required(),
                        Select::make('status')
                            ->options(array_combine(Domain::STATUSES, array_map(fn ($status) => __('domains.statuses.' . $status), Domain::STATUSES)))
                            ->required()
                            ->default(Domain::STATUS_PENDING),
                        Select::make('action')
                            ->label('Order type')
                            ->options(['register' => 'Registration', 'transfer' => 'Transfer'])
                            ->default('register')
                            ->required(),
                        TextInput::make('years')->label('Term (years)')->numeric()->minValue(1)->maxValue(10)->default(1)->required(),
                        TextInput::make('price')->label('Renewal price')->numeric()->minValue(0)->step(0.01)->default(0)->required(),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->relationship('currency', 'code')
                            ->default(config('settings.default_currency'))
                            ->required(),
                        DatePicker::make('registered_at')->label('Registered at'),
                        DatePicker::make('expires_at')->label('Expires at'),
                        TextInput::make('auth_code')->label('Authorization code')->password()->revealable(),
                        TagsInput::make('nameservers')->label('Nameservers')->placeholder('ns1.example.com')->columnSpan(2),
                        Checkbox::make('auto_renew')->label('Auto renew')->default(true),
                        Checkbox::make('privacy')->label('WHOIS privacy'),
                    ]),
            ]);
    }

    private static function setDomain(Get $get, Set $set): void
    {
        $tld = Tld::find($get('tld_id'));
        $set('domain', strtolower(trim((string) $get('name'))) . ($tld ? '.' . $tld->tld : ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('domain')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Customer')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => __('domains.statuses.' . $state))
                    ->color(fn (string $state): string => match ($state) {
                        Domain::STATUS_ACTIVE => 'success',
                        Domain::STATUS_PENDING, Domain::STATUS_PENDING_TRANSFER => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('registrar.name')->label('Registrar')->sortable(),
                TextColumn::make('expires_at')->date()->sortable(),
                TextColumn::make('auto_renew')->label('Auto renew')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Domain::STATUSES, array_map(fn ($status) => __('domains.statuses.' . $status), Domain::STATUSES))),
                SelectFilter::make('registrar')->relationship('registrar', 'name'),
                SelectFilter::make('tld')->relationship('tld', 'tld'),
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
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
}
