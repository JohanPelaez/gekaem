# SYSTEM SPECIFICATION & ARCHITECTURE RULES: GEKAEM

## 1. CONTEXTO DEL PROYECTO Y OBJETIVO DE NEGOCIO
**Gekaem** es un SaaS Multi-Tenant de **Gestión de Procesos y Producción bajo Pedido (Make-to-Order + MRP ligero + Kanban)** diseñado para emprendedores[cite: 1].
- **Propósito:** Permitir a los emprendedores registrar pedidos con tiempos y predicción de fecha de entrega (incluyendo tiempos de holgura)[cite: 1], gestionar productos con variantes arquitectónicamente ortogonales y consumo de materiales[cite: 1], administrar clientes y proveedores[cite: 1], controlar el inventario de materia prima[cite: 1] y operar la producción en un tablero Kanban con estados dinámicos y tipos de flujos por producto[cite: 1].

---

## 2. STACK TECNOLÓGICO Y ESTRUCTURA DE DIRECTORIO ÚNICO (MONOLITO)
- **Carpeta Raíz y Entorno Local:** `gekaem` corriendo sobre Laravel Herd (`http://gekaem.test`).
- **Backend:** PHP 8.3+ con **Laravel 11/12**.
- **Puente Full-Stack:** **Inertia.js v2** (`inertiajs/inertia-laravel` + `@inertiajs/svelte`).
- **Frontend:** **Svelte 5 (Runes)** + TailwindCSS + Vite viviendo **dentro del mismo directorio raíz de Laravel** (`resources/js/`).
- **Base de Datos:** **MySQL 8.0+ (Motor InnoDB)** — Base de datos llamada `gekaem`.

### REGLA CRÍTICA DE DIRECTORIO ÚNICO (LARAVEL + SVELTE)
1. **PROHIBIDO instalar SvelteKit (`@sveltejs/kit`)** o crear carpetas separadas como `frontend/`, `client/` o `src/routes/`.
2. Todo el código de Svelte vive exclusivamente dentro de `resources/js/Pages/`, `resources/js/Components/` y `resources/js/Layouts/`.
3. Todo el enrutamiento de la aplicación se define en Laravel (`routes/web.php`) y retorna vistas de Svelte mediante `Inertia::render('Modulo/Vista', $props)`.
4. **Archivos de Arranque Protegidos:** Los archivos `resources/js/app.js`, `vite.config.js`, `bootstrap/app.php` y `resources/views/app.blade.php` ya están configurados con `mount()` de Svelte 5 e Inertia. **PROHIBIDO modificar, regenerar o sobrescribir estos 4 archivos.**

---

## 3. REGLAS ESTRICTAS DE CÓDIGO (GUARDRAILS PARA EL AGENTE)

### 3.1. Directivas para Svelte 5 (PROHIBIDO SVELTE 3/4)
1. **Uso obligatorio de Runes de Svelte 5:**
   - Recibir props de Inertia: `let { orders, workflows, errors } = $props();` (PROHIBIDO usar `export let`).
   - Estado reactivo local: `let selectedStage = $state(null);`.
   - Valores calculados: `let totalMinutes = $derived(...);` (PROHIBIDO usar `$: total = ...`).
2. **Eventos nativos de Svelte 5:** Usa `onclick={...}`, `onsubmit={...}`, `oninput={...}` (PROHIBIDO usar `on:click` o `createEventDispatcher`).
3. **Snippets en lugar de Slots:** Usa `{#snippet ...}` y `{@render children?.()}` en lugar de `<slot />`.
4. **Mutaciones con Inertia:** Usa `useForm` de `@inertiajs/svelte` para formularios CRUD y `router.patch()` con actualización optimista de `$state` para arrastrar tarjetas en el tablero Kanban[cite: 1].

### 3.2. Directivas para Laravel (Arquitectura Limpia)
1. **Controladores Delgados:** Los controladores en `app/Http/Controllers/` no deben superar las 25 líneas por método. Solo validan mediante `FormRequest`, ejecutan un `Action` y retornan `Inertia::render()` o `redirect()->back()`.
2. **Patrón Action (`app/Actions/`):** Toda lógica de negocio (crear pedidos con cálculo de fecha de entrega[cite: 1], mover tarjetas Kanban y sincronizar el estado del pedido[cite: 1], descontar materia prima[cite: 1], generar variantes ortogonales[cite: 1]) debe vivir en clases `Action` dedicadas y ejecutarse dentro de `DB::transaction()`.
3. **Aislamiento Multi-Tenant (`BelongsToTenant`):** Todos los modelos con `tenant_id` deben usar el trait `App\Traits\BelongsToTenant` para filtrar consultas automáticamente por el `tenant_id` activo.
4. **Tipado estricto:** Incluye `declare(strict_types=1);` en todas las clases PHP, con tipos de parámetros y de retorno explícitos.

### 3.3. Directivas para MySQL (InnoDB) en Base de Datos `gekaem`
1. **Primary Keys:** Usa siempre `$table->id()` (`BIGINT UNSIGNED AUTO_INCREMENT`). Prohibido usar `UUID v4` aleatorios como clave primaria para no fragmentar el índice agrupado de InnoDB.
2. **Tipos numéricos exactos:** Prohibido usar `FLOAT` o `DOUBLE`. Usa `$table->decimal('campo', 15, 2)` para precios/costos y `$table->decimal('campo', 12, 3)` para stock/consumo de materiales[cite: 1].
3. **Índices Compuestos:** `tenant_id` debe ser siempre la primera columna en todos los índices compuestos (`$table->index(['tenant_id', ...])`).
4. **Tabla `users` preexistente:** La migración base `0001_01_01_000000_create_users_table.php` ya fue ejecutada en la base de datos `gekaem`. **NO crees una nueva migración para crear la tabla `users`**; únicamente actualiza el modelo `App\Models\User` para agregar la relación con `Tenant`.

---

## 4. REGLAS DE NEGOCIO DEL DOMINIO (7 MÓDULOS Y 9 NOTAS DEL MVP)

1. **Separación entre Catálogo, Pedidos ("US") y Productos en Kanban ("Tareas"):**
   - **Nota 1:** Los productos solicitados en un pedido actúan como **"Tareas"** (`production_tasks`) dentro del tablero Kanban[cite: 1].
   - **Nota 2:** Los pedidos (`orders`) actúan como **"US" (User Stories)** dentro del tablero Kanban (agrupadores o carriles Swimlane)[cite: 1].
   - El catálogo (`products` y `product_variants`) es la plantilla maestra; lo que cambia de estado en el tablero Kanban es la instancia `production_tasks`[cite: 1].
2. **Sincronización Automática del Estado del Pedido (Nota 6):**
   - El estado de los pedidos (`orders.derived_status`) depende directamente del estado de todos los productos (`production_tasks`) incluidos en ese pedido[cite: 1].
   - Cada columna dinámica (`workflow_stages`) creada por el usuario en el Kanban (Módulo 5)[cite: 1] pertenece a una categoría interna fija (`stage_category`: `'pending' | 'in_progress' | 'blocked' | 'done'`).
   - Al mover una tarea en el Kanban, `orders.derived_status` se recalcula automáticamente[cite: 1]:
     - Todas las tareas en `'pending'` -> Pedido `'pending'`.
     - Al menos una tarea en `'blocked'` -> Pedido `'blocked'`.
     - Todas las tareas en `'done'` -> Pedido `'ready'`.
     - Cualquier otra combinación -> Pedido `'in_progress'`.
3. **Predicción de Fecha de Entrega con Tiempos de Holgura (Módulo 1 y Nota 3):**
   - Es fundamental predecir la fecha de entrega teniendo en cuenta los tiempos de producción y los tiempos de holgura[cite: 1].
   - Fórmula: `Minutos Totales = (Minutos Cola WIP Activa + Minutos Producción del Pedido) * (1 + Porcentaje de Holgura)`[cite: 1], dividido entre los minutos productivos diarios del taller (`tenants.daily_productive_minutes`).
4. **Materiales Primordiales y Proveedores (Módulos 4 y 6; Nota 4):**
   - Los materiales primordiales (`raw_materials`) manejan registro e inventario[cite: 1] y pueden estar amarrados a 1 o más proveedores (`suppliers`), o a ninguno, mediante la tabla pivote opcional `raw_material_supplier`[cite: 1].
5. **Pedidos y Clientes (Módulos 1 y 3; Nota 5):**
   - Todo pedido (`orders`) debe estar obligatoriamente amarrado a un cliente (`customer_id NOT NULL`)[cite: 1].
6. **Flujos Kanban, Consumo de Materiales y Variantes Ortogonales (Módulos 2 y 7; Notas 7, 8 y 9):**
   - **Nota 7:** Todos los productos (`products`) deben estar enlazados obligatoriamente a un flujo Kanban (`workflow_id NOT NULL`) según el producto y su gestión[cite: 1].
   - **Nota 8:** Los productos dependen de 1 o más materiales primordiales (`product_material_requirements`) registrando su consumo de material[cite: 1].
   - **Nota 9:** Los productos son arquitectónicamente ortogonales mediante variantes (`orthogonal_attributes` -> `orthogonal_attribute_options` -> `product_variants`), e incluyen tiempo de producción, tipo de producto y código de barras[cite: 1].

---

## 5. ESQUEMA EXACTO DE BASE DE DATOS (19 TABLAS EN MYSQL `gekaem`)

### Bloque A: Multi-Tenancy y Accesos
1. **`tenants`**: `id`, `name`, `slug` (unique), `daily_productive_minutes` (int unsigned, default 480), `default_buffer_percent` (tinyint unsigned, default 20), `settings` (json nullable), `timestamps`.
2. **`users`** *(Ya existe en migración base de Laravel)*: `id`, `name`, `email`, `password`, `timestamps`. Relación `belongsToMany(Tenant::class)`.
3. **`tenant_user`**: `tenant_id` (FK cascade), `user_id` (FK cascade), `role` (`'owner' | 'operator'`). PK compuesta `['tenant_id', 'user_id']`.

### Bloque B: Clientes y Proveedores (Módulos 3 y 4)
4. **`customers`**[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `phone` (nullable), `email` (nullable), `tax_id` (nullable), `address` (nullable), `notes` (text nullable), `timestamps`. Índice: `['tenant_id', 'name']`.
5. **`suppliers`**[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `contact_name` (nullable), `phone` (nullable), `email` (nullable), `lead_time_days` (smallint unsigned, default 1), `timestamps`. Índice: `['tenant_id', 'name']`.

### Bloque C: Materia Prima e Inventario (Módulo 6 y Nota 4)
6. **`raw_materials`**[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `sku` (nullable), `unit_of_measure` (string 20), `current_stock` (decimal 12,3 default 0)[cite: 1], `reserved_stock` (decimal 12,3 default 0), `min_stock_alert` (decimal 12,3 default 0), `unit_cost` (decimal 15,2 default 0), `timestamps`. Índice: `['tenant_id', 'name']`.
7. **`raw_material_supplier`** (Nota 4)[cite: 1]: `id`, `raw_material_id` (FK cascade), `supplier_id` (FK cascade), `purchase_price` (decimal 15,2 nullable), `is_preferred` (boolean default false), `timestamps`. Unique: `['raw_material_id', 'supplier_id']`.
8. **`raw_material_movements`**[cite: 1]: `id`, `tenant_id` (FK cascade), `raw_material_id` (FK cascade), `production_task_id` (FK nullable), `type` (`'purchase' | 'consumption' | 'adjustment' | 'return'`), `quantity` (decimal 12,3), `stock_after` (decimal 12,3), `notes` (string nullable), `timestamps`.

### Bloque D: Tipos de Flujos y Estados Dinámicos Kanban (Módulos 5 y 7)
9. **`workflows`**[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `description` (nullable), `is_default` (boolean default false), `timestamps`. Índice: `['tenant_id', 'is_default']`.
10. **`workflow_stages`**[cite: 1]: `id`, `workflow_id` (FK cascade), `name`, `color` (string 20, default `'#64748b'`), `position` (smallint unsigned), `stage_category` (enum: `'pending'`, `'in_progress'`, `'blocked'`, `'done'`), `wip_limit` (smallint unsigned nullable), `timestamps`. Índice: `['workflow_id', 'position']`.

### Bloque E: Productos, Variantes Ortogonales y Consumo (Módulo 2; Notas 7, 8 y 9)
11. **`product_types`**[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `timestamps`.
12. **`products`**[cite: 1]: `id`, `tenant_id` (FK cascade), `product_type_id` (FK nullable nullOnDelete)[cite: 1], `workflow_id` (FK restrictOnDelete - Nota 7)[cite: 1], `name`, `barcode` (string 100 nullable)[cite: 1], `base_production_minutes` (int unsigned)[cite: 1], `buffer_percentage` (tinyint unsigned default 20)[cite: 1], `base_price` (decimal 15,2), `is_active` (boolean default true), `timestamps`. Índices: `['tenant_id', 'is_active']`, `['tenant_id', 'barcode']`.
13. **`orthogonal_attributes`** (Nota 9)[cite: 1]: `id`, `tenant_id` (FK cascade), `name`, `timestamps`.
14. **`orthogonal_attribute_options`** (Nota 9)[cite: 1]: `id`, `orthogonal_attribute_id` (FK cascade), `value`, `sku_suffix` (string 20 nullable), `timestamps`.
15. **`product_variants`** (Nota 9)[cite: 1]: `id`, `product_id` (FK cascade), `name_label` (string 150), `sku` (string 80 nullable), `barcode` (string 100 nullable)[cite: 1], `production_minutes_delta` (int default 0)[cite: 1], `price_override` (decimal 15,2 nullable), `is_active` (boolean default true), `timestamps`.
16. **`product_variant_options`** (Nota 9)[cite: 1]: `product_variant_id` (FK cascade), `orthogonal_attribute_option_id` (FK cascade con nombre corto de índice `fk_pvo_option`). PK compuesta.
17. **`product_material_requirements`** (Nota 8)[cite: 1]: `id`, `product_id` (FK cascade), `product_variant_id` (FK nullable cascade), `raw_material_id` (FK restrictOnDelete)[cite: 1], `quantity_required` (decimal 12,3)[cite: 1], `waste_percent` (decimal 5,2 default 0), `timestamps`.

### Bloque F: Pedidos ("US") y Tareas en Tablero Kanban (Módulos 1 y 5; Notas 1, 2, 3, 5 y 6)
18. **`orders`** (Pedidos = "US" en Kanban)[cite: 1]: `id`, `tenant_id` (FK cascade), `customer_id` (FK restrictOnDelete, NOT NULL - Nota 5)[cite: 1], `order_number` (int unsigned), `derived_status` (enum: `'pending'`, `'in_progress'`, `'blocked'`, `'ready'`, `'delivered'` - Nota 6)[cite: 1], `total_production_minutes` (int unsigned default 0)[cite: 1], `buffer_minutes` (int unsigned default 0)[cite: 1], `predicted_delivery_at` (dateTime nullable - Nota 3)[cite: 1], `committed_delivery_at` (dateTime nullable)[cite: 1], `delivered_at` (dateTime nullable), `kanban_swimlane_position` (int unsigned default 0), `total_amount` (decimal 15,2 default 0), `notes` (text nullable), `timestamps`. Índice: `['tenant_id', 'derived_status', 'kanban_swimlane_position']` (nombre: `idx_orders_kanban`).
19. **`production_tasks`** (Productos = "Tareas" en Kanban)[cite: 1]: `id`, `tenant_id` (FK cascade), `order_id` (FK cascade - enlace a la "US")[cite: 1], `product_variant_id` (FK restrictOnDelete), `workflow_id` (FK restrictOnDelete)[cite: 1], `current_stage_id` (FK restrictOnDelete a `workflow_stages`)[cite: 1], `item_name_snapshot` (string 150), `quantity` (decimal 10,2 default 1), `unit_price` (decimal 15,2 default 0), `estimated_minutes` (int unsigned)[cite: 1], `column_position` (int unsigned default 0), `materials_consumed` (boolean default false), `started_at` (timestamp nullable), `completed_at` (timestamp nullable), `timestamps`. Índices: `['tenant_id', 'workflow_id', 'current_stage_id', 'column_position']` (nombre: `idx_tasks_kanban_col`), `['order_id', 'current_stage_id']`.

---

## 6. MAPA DE ARCHIVOS DEL MONOLITO (`gekaem`)
```text
gekaem/
├── app/
│   ├── Actions/
│   │   ├── Catalog/          # Productos, variantes ortogonales y recetas BOM
│   │   ├── Inventory/        # Kardex y consumo de materia prima
│   │   ├── Kanban/           # MoveProductionTaskAction, SyncOrderDerivedStatusAction
│   │   └── Orders/           # CreateOrderAction, PredictDeliveryDateAction
│   ├── Http/
│   │   ├── Controllers/      # Controladores Inertia (máx 25 líneas por método)
│   │   └── Requests/         # FormRequests de validación
│   ├── Models/               # 19 Modelos Eloquent
│   └── Traits/
│       └── BelongsToTenant.php
├── resources/
│   ├── views/
│   │   └── app.blade.php     # PROTEGIDO (No modificar)
│   └── js/
│       ├── app.js            # PROTEGIDO (Svelte 5 mount + Inertia)
│       ├── Components/       # Componentes reutilizables Svelte 5 (Runes)
│       ├── Layouts/          # AppLayout.svelte
│       └── Pages/            # Vistas de Inertia (.svelte)
│           ├── Orders/       # Módulo 1: Pedidos y predicción de entrega
│           ├── Products/     # Módulo 2: Productos, variantes ortogonales y BOM
│           ├── Customers/    # Módulo 3: Clientes
│           ├── Suppliers/    # Módulo 4: Proveedores
│           ├── Kanban/       # Módulo 5: Tablero Kanban (US = Pedidos, Tareas = Productos)
│           ├── RawMaterials/ # Módulo 6: Materia prima e inventario
│           └── Workflows/    # Módulo 7: Tipos de flujos y estados dinámicos
├── routes/
│   └── web.php               # Único archivo de rutas de la aplicación
└── vite.config.js            # PROTEGIDO (No modificar)