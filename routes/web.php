<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('WelcomeGekaem', [
        'proyecto'      => 'Gekaem MVP',
        'versionPhp'    => PHP_VERSION,
        'baseDatos'     => DB::connection()->getDatabaseName(),
        'pedidosEnCola' => 120, // Simulación de 2 horas de pedidos previos en el Kanban
    ]);
});