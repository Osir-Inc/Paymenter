<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\InvoiceResource;
use App\Helpers\ExtensionHelper;
use App\Jobs\Domain\RegisterJob;
use App\Jobs\Domain\RenewJob;
use App\Jobs\Domain\TransferJob;
use App\Models\Domain;
use App\Services\Domain\DomainRenewalInvoiceService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provision')
                ->label(fn (Domain $record) => $record->action === Domain::ACTION_TRANSFER ? 'Start transfer' : 'Register at registrar')
                ->icon('ri-send-plane-line')
                ->visible(fn (Domain $record) => $record->status === Domain::STATUS_PENDING)
                ->requiresConfirmation()
                ->schema([
                    Checkbox::make('sendNotification')->label('Send notification')->default(true),
                ])
                ->action(function (array $data, Domain $record) {
                    $this->run(fn () => $record->action === Domain::ACTION_TRANSFER
                        ? (new TransferJob($record))->handle()
                        : (new RegisterJob($record, (bool) $data['sendNotification']))->handle());
                }),
            Action::make('renew')
                ->label('Renew at registrar')
                ->icon('ri-refresh-line')
                ->visible(fn (Domain $record) => $record->isManageable())
                ->schema([
                    TextInput::make('years')->label('Years')->numeric()->minValue(1)->maxValue(10)->default(fn (Domain $record) => $record->years)->required(),
                    Checkbox::make('sendNotification')->label('Send notification')->default(false),
                ])
                ->action(function (array $data, Domain $record) {
                    $this->run(fn () => (new RenewJob($record, (int) $data['years'], (bool) $data['sendNotification']))->handle());
                }),
            Action::make('invoice')
                ->label('Create renewal invoice')
                ->icon('ri-bill-line')
                ->visible(fn (Domain $record) => $record->isManageable())
                ->schema([
                    TextInput::make('years')->label('Years')->numeric()->minValue(1)->maxValue(10)->default(fn (Domain $record) => $record->years)->required(),
                ])
                ->action(function (array $data, Domain $record) {
                    $invoice = (new DomainRenewalInvoiceService)->create($record, (int) $data['years']);
                    if (!$invoice) {
                        Notification::make()->title('No renewal price configured for this term and currency')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Invoice created')->success()->send();
                    $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
                }),
            Action::make('sync')
                ->label('Sync from registrar')
                ->icon('ri-cloud-line')
                ->visible(fn (Domain $record) => in_array($record->status, [Domain::STATUS_ACTIVE, Domain::STATUS_EXPIRED, Domain::STATUS_PENDING_TRANSFER]))
                ->action(function (Domain $record) {
                    $this->run(function () use ($record) {
                        $info = ExtensionHelper::callDomain($record, 'getDomainInfo') ?? [];
                        $record->fill(array_filter([
                            'status' => in_array($info['status'] ?? null, Domain::STATUSES) ? $info['status'] : null,
                            'expires_at' => isset($info['expires_at']) ? Carbon::parse($info['expires_at']) : null,
                            'nameservers' => $info['nameservers'] ?? null,
                        ]));
                        if (array_key_exists('privacy', $info)) {
                            $record->privacy = (bool) $info['privacy'];
                        }
                        $record->save();
                    });
                }),
            DeleteAction::make(),
        ];
    }

    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (Exception $e) {
            report($e);
            Notification::make()->title('Registrar error')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Done')->success()->send();
        $this->fillForm();
    }
}
