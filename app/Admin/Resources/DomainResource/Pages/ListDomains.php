<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\DomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
