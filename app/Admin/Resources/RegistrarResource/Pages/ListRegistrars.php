<?php

namespace App\Admin\Resources\RegistrarResource\Pages;

use App\Admin\Resources\RegistrarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistrars extends ListRecords
{
    protected static string $resource = RegistrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
