<?php

declare(strict_types=1);

return [
    'annual_discount_percent' => 20,
    'trial_days' => 15,

    'plans' => [
        'basic' => [
            'key' => 'basic',
            'name' => 'Plan Básico',
            'monthly_price' => 30000,
            'annual_price' => 288000,
            'limits' => [
                'ceo' => 1,
                'worker' => 0,
                'workflows' => 2,
            ],
            'features' => [
                '15 días de prueba gratis',
                '1 Usuario CEO - Administrador',
                '2 Flujos de trabajo',
                'Dashboard',
                'Tablero Kanban de pedidos',
                '2 Tableros Kanban de productos y servicios',
                'Gestión de pedidos',
                'Gestión de clientes',
                'Catálogo de productos y/o servicios',
                'Gestión de materias primas',
                'Recetas BOM',
                'Gestión de proveedores',
            ],
        ],
        'pro' => [
            'key' => 'pro',
            'name' => 'Plan Profesional',
            'monthly_price' => 65000,
            'annual_price' => 624000,
            'limits' => [
                'ceo' => 1,
                'worker' => 3,
                'workflows' => 3,
            ],
            'features' => [
                '15 días de prueba gratis',
                '1 Usuario CEO - Administrador',
                '3 Usuarios Operario',
                '3 Flujos de trabajo',
                'Dashboard',
                'Tablero Kanban de pedidos',
                '3 Tableros Kanban de productos y servicios',
                'Gestión de pedidos',
                'Gestión de clientes',
                'Catálogo de productos y/o servicios',
                'Gestión de materias primas',
                'Recetas BOM',
                'Gestión de proveedores',
            ],
        ],
    ],

    'addons' => [
        'ceo' => [
            'key' => 'ceo',
            'name' => 'Usuario CEO - Administrador',
            'monthly_price' => 20000,
            'annual_price' => 192000,
            'features' => [
                'Acceso a la gestión de todos los módulos de la aplicación',
                'Visibilidad de valores de los pedidos y productos',
            ],
        ],
        'worker' => [
            'key' => 'worker',
            'name' => 'Usuario Operario',
            'monthly_price' => 15000,
            'annual_price' => 144000,
            'features' => [
                'Acceso restringido a la visibilidad de valores y Dashboard',
                'No podrá gestionar los módulos de la aplicación',
                'Acceso a los tableros de Kanban',
                'Solicitud de materias primas',
            ],
        ],
        'workflow' => [
            'key' => 'workflow',
            'name' => 'Flujo de Trabajo',
            'monthly_price' => 15000,
            'annual_price' => 144000,
            'features' => [
                'Flujo de trabajo para la gestión operativa de un nuevo producto o servicio',
            ],
        ],
    ],
];
