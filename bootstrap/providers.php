<?php

use App\AI\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    AdminPanelProvider::class,
];
