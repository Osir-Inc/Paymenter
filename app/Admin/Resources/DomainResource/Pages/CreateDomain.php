<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\DomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;
}
