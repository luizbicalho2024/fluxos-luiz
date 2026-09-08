<?php
use Illuminate\Support\Facades\Artisan;

Artisan::command('about-fluxos', function () {
    $this->info('Fluxos Luiz - Laravel + MongoDB');
});
