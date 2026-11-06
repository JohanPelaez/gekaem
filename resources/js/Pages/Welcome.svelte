<script>
    import { page, Link } from "@inertiajs/svelte";
    import BrandLogo from "../Components/BrandLogo.svelte";

    let { canLogin = true, canRegister = true } = $props();

    // Usuario autenticado (reactivo vía props de Inertia)
    let authUser = $derived(page.props.auth?.user);

    // Estado reactivo de tema (Claro / Oscuro)
    let isDark = $state(false);

    // Estado del escaparate interactivo de capturas
    // 'split' = Comparador deslizable, 'layered' = Vista dual en capas, 'light' = Solo claro, 'dark' = Solo oscuro
    let showcaseMode = $state("split");
    let sliderPos = $state(50); // Porcentaje de división (0 a 100)
    let activeLayer = $state("light"); // En modo capas: cuál está al frente ('light' | 'dark')
    let isDragging = $state(false);
    let sliderContainerRef = $state(null);

    // Control de fallos de imagen (resguardo inteligente)
    let lightImgFailed = $state(false);
    let darkImgFailed = $state(false);

    // Estado reactivo de la Sección de Módulos (#modulos)
    let activeModuleIndex = $state(0);
    let activeSubImageIndex = $state(0);
    let zoomedImage = $state(null);
    let moduleImgErrors = $state({});

    const modulesShowcase = [
        {
            id: "pedidos",
            tabLabel: "📦 1. Pedidos",
            badge: "MÓDULO CENTRAL • FINANZAS Y ENTREGAS",
            title: "Control total de cada pedido, desde el abono inicial hasta la entrega",
            description:
                "Registra pedidos en segundos, controla cuánto te han abonado y cuánto falta por cobrar, define si el envío es contraentrega o incluido y compromete fechas reales según la capacidad de tu taller.",
            points: [
                "Cálculo en vivo de Total, Abonos y Saldo Pendiente con soporte de descuentos por ítem.",
                "Identificación clara de modalidad de envío (Contraentrega o Envío Incluido) y estado en píldoras de color.",
                "Creación de orden de producción automática con tiempos estimados y seguimiento hasta el despacho.",
            ],
            images: [
                {
                    src: "/images/landing/mod-pedidos.png",
                    label: "Panel y Control de Pedidos",
                    caption:
                        "Vista general de pedidos, abonos, saldos pendientes y estados en tiempo real.",
                },
                {
                    src: "/images/landing/mod-ficha-tarea.png",
                    label: "Ficha de Orden en Producción",
                    caption:
                        "Detalle de tarea por pedido con tiempo estimado, etapa actual y código de barras.",
                },
            ],
        },
        {
            id: "productos",
            tabLabel: "🏷️ 2. Productos y BOM",
            badge: "INGENIERÍA DE CATÁLOGO • CÓDIGOS DE BARRAS",
            title: "Catálogo inteligente con variantes ortogonales, recetas (BOM) y códigos de barras",
            description:
                "Configura tus productos una sola vez: asigna su flujo Kanban, define tiempos de fabricación con porcentaje de holgura, arma su receta de insumos y genera automáticamente todas las combinaciones de Talla, Color o Material.",
            points: [
                "Generador integrado de Códigos de Barras (Code 128 / EAN-13) descargables en PNG listos para imprimir.",
                "Receta de Materiales Primordiales (BOM) que descuenta automáticamente insumos al pasar a producción.",
                "Cálculo de Tiempo de Fabricación y Porcentaje de Holgura (%) para medir la carga real del taller.",
            ],
            images: [
                {
                    src: "/images/landing/mod-productos.png",
                    label: "Configurador de Producto y Receta BOM",
                    caption:
                        "Creación de producto con flujo Kanban, código de barras, tiempos, holgura y variantes.",
                },
                {
                    src: "/images/landing/mod-ficha-tarea.png",
                    label: "Ficha Técnica y Etiqueta PNG",
                    caption:
                        "Ficha técnica de variante con código de barras CODE128 descargable y estado de descuento BOM.",
                },
            ],
        },
        {
            id: "materia-prima",
            tabLabel: "🧱 3. Materia Prima",
            badge: "KARDEX INTELIGENTE • ALERTAS DE STOCK",
            title: "Que nunca se detenga tu producción por falta de un insumo",
            description:
                "Monitorea en tiempo real cuánto material tienes físicamente en bodega, cuánto está reservado por pedidos activos y cuánto tienes realmente disponible antes de comprometer una nueva entrega.",
            points: [
                "Triple métrica por insumo: Stock Actual, Stock Reservado y Stock Disponible real.",
                "Umbral de Alerta personalizable por unidad de medida (metros, unidades, conos, kilos) con filtro de Stock Bajo.",
                "Ajustes rápidos de inventario (± Stock) y trazabilidad directa con los proveedores que lo suministran.",
            ],
            images: [
                {
                    src: "/images/landing/mod-materia-prima.png",
                    label: "Control de Materia Prima e Inventario",
                    caption:
                        "Tarjetas de insumos con stock actual, reservado, disponible, alertas y proveedores.",
                },
            ],
        },
        {
            id: "clientes",
            tabLabel: "👥 4. Clientes",
            badge: "CRM PARA TALLERES • HISTORIAL COMERCIAL",
            title: "Toda la información de tus clientes y sus pedidos a un clic",
            description:
                "Centraliza los datos fiscales, de contacto y de ubicación de tus clientes corporativos o individuales para despachar sin errores y consultar su historial de compras al instante.",
            points: [
                "Registro completo con Cédula / NIT, teléfono, correo, departamento, ciudad, barrio y dirección exacta.",
                "Contador automático de pedidos por cliente y notas de preferencias o personalización.",
                "Creación rápida (Quick Create) directamente desde el formulario de nuevo pedido sin perder el hilo.",
            ],
            images: [
                {
                    src: "/images/landing/mod-clientes.png",
                    label: "Directorio de Clientes",
                    caption:
                        "Fichas de clientes con documento/NIT, datos de entrega, notas y acceso directo a sus pedidos.",
                },
            ],
        },
        {
            id: "proveedores",
            tabLabel: "🚚 5. Proveedores",
            badge: "CADENA DE SUMINISTRO • LEAD TIME",
            title: "Controla quién te provee cada insumo y cuántos días tarda en llegar",
            description:
                "Conecta tus materias primas con tus proveedores, conoce sus tiempos de reposición (Lead Time) y ten siempre a la mano sus datos de contacto y medios de pago.",
            points: [
                "Indicador visual de Lead Time (días de entrega del proveedor) para planificar compras a tiempo.",
                "Vinculación directa de qué materiales suministra cada proveedor con costos de referencia.",
                "Registro de persona de contacto, teléfono, email, medios de pago (Nequi, transferencia, etc.) y notas.",
            ],
            images: [
                {
                    src: "/images/landing/mod-proveedores.png",
                    label: "Directorio de Proveedores",
                    caption:
                        "Directorio de proveedores con tiempos de entrega (Lead Time), contacto y materiales asociados.",
                },
            ],
        },
    ];

    const flowSteps = [
        {
            stepNumber: 1,
            moduleIndex: 0,
            icon: "📦",
            title: "Pedidos y Abonos",
            description:
                "Recibe el pedido, calcula abonos, saldos y fecha de entrega.",
        },
        {
            stepNumber: 2,
            moduleIndex: 1,
            icon: "🏷️",
            title: "Productos y BOM",
            description:
                "Aplica receta de insumos, variantes ortogonales y código de barras.",
        },
        {
            stepNumber: 3,
            moduleIndex: 2,
            icon: "🧱",
            title: "Materia Prima",
            description:
                "Reserva stock automático y controla disponible vs. mínimo.",
        },
        {
            stepNumber: 4,
            moduleIndex: 3,
            icon: "👥",
            title: "Clientes",
            description:
                "Centraliza NIT/Cédula, dirección de despacho e historial.",
        },
        {
            stepNumber: 5,
            moduleIndex: 4,
            icon: "🚚",
            title: "Proveedores",
            description:
                "Gestiona abastecimiento, costos y días de entrega (Lead Time).",
        },
    ];

    let currentModule = $derived(modulesShowcase[activeModuleIndex]);
    let currentImage = $derived(
        currentModule?.images[activeSubImageIndex] || currentModule?.images[0],
    );

    function selectModule(index) {
        activeModuleIndex = index;
        activeSubImageIndex = 0;
    }

    function nextModule() {
        activeModuleIndex = (activeModuleIndex + 1) % modulesShowcase.length;
        activeSubImageIndex = 0;
    }

    function prevModule() {
        activeModuleIndex =
            (activeModuleIndex - 1 + modulesShowcase.length) %
            modulesShowcase.length;
        activeSubImageIndex = 0;
    }

    // =========================================================================
    // Estado reactivo del Centro de Control Kanban (#kanban)
    // =========================================================================
    let activeKanbanTab = $state(0);
    let kanbanImgErrors = $state({});

    const kanbanTabs = [
        {
            id: "macro",
            dockLabel: "Tablero Macro",
            icon: "🗂️",
            badge: "CONTROL GLOBAL • SWIMLANES POR PEDIDO",
            title: "Tablero Macro de Pedidos",
            image: {
                src: "/images/landing/kanban-macro.png",
                label: "Tablero Macro de Pedidos del Taller",
                caption:
                    "Vista integral de todos los pedidos activos divididos en carriles horizontales y columnas dinámicas.",
            },
            highlights: [
                {
                    icon: "🔀",
                    title: "Swimlanes por Pedido",
                    desc: "Cada orden organiza sus productos en carriles limpios e independientes para evitar confusiones.",
                },
                {
                    icon: "⚡",
                    title: "Sincronización en Vivo",
                    desc: "Al mover la última tarea a despacho, el estado del pedido cambia a 'Listo' automáticamente.",
                },
                {
                    icon: "📊",
                    title: "Detección de Cuellos de Botella",
                    desc: "Visualiza al instante qué etapas acumulan más trabajo antes de que se retrasen tus entregas.",
                },
            ],
        },
        {
            id: "aislado",
            dockLabel: "Vista Aislada por Pedido",
            icon: "🔍",
            badge: "FOCO TOTAL • SEGUIMIENTO POR ORDEN",
            title: "Tablero Aislado por Pedido",
            image: {
                src: "/images/landing/kanban-aislado.png",
                label: "Tablero Aislado de Producción por Pedido",
                caption:
                    "Filtro focalizado exclusivamente en las tareas, etapas y variantes de un pedido específico.",
            },
            highlights: [
                {
                    icon: "🎯",
                    title: "Enfoque sin Dispersión",
                    desc: "Examina únicamente las prendas, tallas y variantes correspondientes a la orden seleccionada.",
                },
                {
                    icon: "🏷️",
                    title: "Ficha Técnica y Tiempos",
                    desc: "Consulta minutos estimados, operario a cargo y códigos de barras CODE128 al instante.",
                },
                {
                    icon: "🧱",
                    title: "Consumo Automático BOM",
                    desc: "Verifica que los insumos y materias primas hayan sido reservados y descontados del kardex.",
                },
            ],
        },
        {
            id: "flujos",
            dockLabel: "Tipos de Flujos y WIP",
            icon: "⚙️",
            badge: "ARQUITECTURA MULTIFLUJO • CAPACIDAD BALANCEADA",
            title: "Tipos de Flujos y Límites WIP",
            image: {
                src: "/images/landing/kanban-flujos.png",
                label: "Configuración de Flujos de Producción",
                caption:
                    "Diseña flujos diferenciados por línea de producto con límites WIP y colores distintivos.",
            },
            highlights: [
                {
                    icon: "🔀",
                    title: "Rutas por Tipo de Producto",
                    desc: "Asigna a cada artículo de tu catálogo su propio flujo de producción especializado.",
                },
                {
                    icon: "🛑",
                    title: "Límites WIP Estrictos",
                    desc: "Fija un tope máximo de tareas por columna para evitar sobrecargar a tus trabajadores.",
                },
                {
                    icon: "📋",
                    title: "Reglas de Estado Universales",
                    desc: "Mapea tus etapas a categorías fijas (Pendiente, En Progreso, Bloqueado, Hecho) sin esfuerzo.",
                },
            ],
        },
        {
            id: "estados",
            dockLabel: "Estados y Colores HEX",
            icon: "🎨",
            badge: "PERSONALIZACIÓN 100% • DRAG & DROP",
            title: "Estados y Columnas Personalizables",
            image: {
                src: "/images/landing/kanban-modal-estado.png",
                label: "Modal de Creación y Edición de Estados",
                caption:
                    "Crea, edita, cambia el color HEX y reordena columnas fácilmente con arrastrar y soltar (Drag & Drop).",
            },
            highlights: [
                {
                    icon: "🖐️",
                    title: "Arrastra y Suelta Intuitivo",
                    desc: "Reordena la secuencia lógica de tus columnas arrastrando y soltando dentro del flujo.",
                },
                {
                    icon: "🎨",
                    title: "Paleta de Colores HEX",
                    desc: "Identifica visualmente cada etapa desde cualquier rincón del taller con colores propios.",
                },
                {
                    icon: "✨",
                    title: "Sincronización Instantánea",
                    desc: "Cualquier cambio en los estados se refleja de inmediato en los tableros Kanban del equipo.",
                },
            ],
        },
    ];

    let currentKanban = $derived(kanbanTabs[activeKanbanTab]);

    function selectKanbanTab(index) {
        activeKanbanTab = index;
    }

    // =========================================================================
    // Estado reactivo de Preguntas Frecuentes (FAQ) (#faq)
    // =========================================================================
    let openFaqIndex = $state(0);

    const faqs = [
        {
            question: "¿Qué pasa cuando terminan los 15 días de prueba gratis?",
            answer:
                "Tu taller se pausa de forma segura hasta que elijas un plan, no perdemos tus datos. Toda tu información de pedidos, clientes, recetas BOM e inventario queda protegida y disponible para reactivarse en cualquier momento cuando decidas continuar.",
        },
        {
            question: "¿Puedo agregar más usuarios después?",
            answer:
                "Sí, en el Plan Estándar puedes sumar usuarios extra por solo $15.000 COP/mes cada uno según crezca tu equipo de trabajo. En el Plan Taller Pro ya cuentas con hasta 5 usuarios incluidos en la tarifa base.",
        },
        {
            question: "¿Cómo se realizan los pagos en Colombia?",
            answer:
                "Es 100% compatible con transferencias bancarias locales (Bancolombia, Davivienda, etc.), billeteras móviles como Nequi y Daviplata, y pasarelas de pago seguras con tarjetas débito/crédito y PSE en línea.",
        },
        {
            question: "¿Necesito instalar algo en mis computadores?",
            answer:
                "No, gipPyme funciona 100% en la nube desde cualquier navegador web (Chrome, Edge, Safari, Firefox) en computadores, tablets o teléfonos móviles, sin necesidad de instalaciones técnicas ni servidores locales.",
        },
    ];

    function toggleFaq(index) {
        openFaqIndex = openFaqIndex === index ? null : index;
    }

    // Estado del menú móvil y scroll
    let mobileMenuOpen = $state(false);
    let isScrolled = $state(false);

    // Inicialización del tema y oyentes de ventana
    $effect(() => {
        if (typeof window !== "undefined") {
            const savedTheme = localStorage.getItem("theme");
            if (
                savedTheme === "dark" ||
                (!savedTheme &&
                    window.matchMedia("(prefers-color-scheme: dark)").matches)
            ) {
                isDark = true;
                document.documentElement.classList.add("dark");
            } else {
                isDark = false;
                document.documentElement.classList.remove("dark");
            }

            const handleScroll = () => {
                isScrolled = window.scrollY > 20;
            };

            window.addEventListener("scroll", handleScroll, { passive: true });
            handleScroll();

            return () => {
                window.removeEventListener("scroll", handleScroll);
            };
        }
    });

    function toggleTheme() {
        isDark = !isDark;
        if (typeof window !== "undefined") {
            if (isDark) {
                document.documentElement.classList.add("dark");
                localStorage.setItem("theme", "dark");
            } else {
                document.documentElement.classList.remove("dark");
                localStorage.setItem("theme", "light");
            }
        }
    }

    function scrollToSection(id) {
        mobileMenuOpen = false;
        if (typeof document !== "undefined") {
            const el = document.getElementById(id);
            if (el) {
                el.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        }
    }

    // Manejo del arrastre del slider en el comparador interactivo
    function handleSliderMove(clientX) {
        if (!sliderContainerRef) return;
        const rect = sliderContainerRef.getBoundingClientRect();
        const offsetX = clientX - rect.left;
        const percentage = Math.min(
            Math.max((offsetX / rect.width) * 100, 2),
            98,
        );
        sliderPos = Math.round(percentage);
    }

    function onPointerDown(e) {
        isDragging = true;
        handleSliderMove(e.clientX);
    }

    function onPointerMove(e) {
        if (isDragging) {
            handleSliderMove(e.clientX);
        }
    }

    function onPointerUp() {
        isDragging = false;
    }

    function toggleLayers() {
        activeLayer = activeLayer === "light" ? "dark" : "light";
    }
</script>

<svelte:head>
    <title
        >gipPyme — Gestor Inteligente de Pedidos, Producción e Inventario</title
    >
    <meta
        name="description"
        content="La aplicación moderna y fácil para talleres y pymes: controla cotizaciones, abonos, tablero Kanban por flujos, recetas de materiales (BOM) y fecha real de entrega."
    />
</svelte:head>

<svelte:window
    onpointermove={onPointerMove}
    onpointerup={onPointerUp}
    onkeydown={(e) => {
        if (e.key === "Escape" && zoomedImage) {
            zoomedImage = null;
        }
    }}
/>

<div
    class="min-h-screen bg-[#F8FAFC] dark:bg-[#0B0F17] text-slate-800 dark:text-slate-100 transition-colors duration-300 selection:bg-[#4F46E5] selection:text-white font-sans overflow-x-hidden"
>
    <!-- ========================================================================= -->
    <!-- 1. NAVBAR FLOTANTE CON EFECTO CRISTAL (GLASSMORPHISM)                     -->
    <!-- ========================================================================= -->
    <header
        class="fixed top-0 inset-x-0 z-50 transition-all duration-300 {isScrolled
            ? 'backdrop-blur-xl bg-white/85 dark:bg-[#0B0F17]/85 border-b border-slate-200/80 dark:border-[#26334D]/90 shadow-sm shadow-slate-900/5'
            : 'backdrop-blur-lg bg-white/70 dark:bg-[#0B0F17]/70 border-b border-slate-200/50 dark:border-[#26334D]/60'}"
    >
        <div
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between gap-4"
        >
            <!-- Izquierda: Componente BrandLogo en versión compacta horizontal -->
            <div class="flex items-center">
                <a
                    href="/"
                    class="focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4F46E5] rounded-xl transition-transform active:scale-95"
                    aria-label="gipPyme Inicio"
                >
                    <BrandLogo
                        variant="horizontal"
                        size="md"
                        showSubtitle={true}
                    />
                </a>
            </div>

            <!-- Centro (Desktop): Enlaces de navegación suaves -->
            <nav
                class="hidden md:flex items-center gap-1 lg:gap-2 text-sm font-medium text-slate-600 dark:text-slate-300"
            >
                <button
                    type="button"
                    onclick={() => scrollToSection("producto")}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Producto
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection("escaparate-demo")}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Capturas Reales
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection("modulos")}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Módulos
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection("kanban")}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Kanban Multiflujo
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection("precios")}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Precios
                </button>
            </nav>

            <!-- Derecha: Tema + Acceso de usuario o CTA comercial -->
            <div class="flex items-center gap-2.5 sm:gap-3">
                <!-- Botón Toggle de Tema Global -->
                <button
                    type="button"
                    onclick={toggleTheme}
                    class="p-2 sm:px-3 sm:py-2 rounded-xl text-xs font-semibold border border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#1f293d] transition-all flex items-center gap-1.5 shadow-sm"
                    title={isDark
                        ? "Cambiar a Modo Claro"
                        : "Cambiar a Modo Oscuro"}
                    aria-label="Alternar tema claro u oscuro"
                >
                    {#if isDark}
                        <span class="text-amber-400 text-sm">☀️</span>
                        <span class="hidden sm:inline">Claro</span>
                    {:else}
                        <span class="text-indigo-500 text-sm">🌙</span>
                        <span class="hidden sm:inline">Oscuro</span>
                    {/if}
                </button>

                {#if authUser}
                    <!-- Usuario Autenticado: Ir directo a su Taller -->
                    <Link
                        href="/dashboard"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-[#4F46E5] to-[#3B82F6] hover:from-[#4338CA] hover:to-[#2563EB] shadow-lg shadow-[#4F46E5]/25 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0"
                    >
                        <span>Ir a mi Taller</span>
                        <span
                            class="transition-transform group-hover:translate-x-1"
                            >→</span
                        >
                    </Link>
                {:else}
                    <!-- Visitante: Iniciar Sesión y Crear Taller -->
                    {#if canLogin}
                        <Link
                            href="/login"
                            class="hidden sm:inline-flex px-3.5 py-2 text-sm font-semibold text-slate-700 dark:text-slate-300 hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors"
                        >
                            Iniciar Sesión
                        </Link>
                    {/if}

                    <Link
                        href="/register"
                        class="inline-flex items-center gap-1.5 bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-lg shadow-[#4F46E5]/25 hover:shadow-[#4F46E5]/40 rounded-xl px-4 sm:px-5 py-2.5 text-sm font-semibold transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0"
                    >
                        <span>Crear mi Taller Gratis</span>
                    </Link>
                {/if}

                <!-- Botón Hamburguesa Móvil -->
                <button
                    type="button"
                    onclick={() => (mobileMenuOpen = !mobileMenuOpen)}
                    class="md:hidden p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#151D2C] transition-colors"
                    aria-label="Abrir menú de navegación"
                >
                    <svg
                        class="w-6 h-6"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        {#if mobileMenuOpen}
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        {:else}
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                        {/if}
                    </svg>
                </button>
            </div>
        </div>

        <!-- Menú Desplegable Móvil -->
        {#if mobileMenuOpen}
            <div
                class="md:hidden px-4 pt-2 pb-6 space-y-3 bg-white/95 dark:bg-[#0B0F17]/95 backdrop-blur-2xl border-b border-slate-200 dark:border-[#26334D] transition-all"
            >
                <div class="grid grid-cols-2 gap-2 text-sm font-medium">
                    <button
                        type="button"
                        onclick={() => scrollToSection("producto")}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        📦 Producto
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection("escaparate-demo")}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        🖼️ Capturas Reales
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection("modulos")}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        🧩 Módulos
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection("kanban")}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        ⚡ Tablero Kanban
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection("precios")}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C] col-span-2"
                    >
                        🏷️ Planes y Precios
                    </button>
                </div>

                <div
                    class="pt-3 border-t border-slate-200 dark:border-[#26334D] flex flex-col gap-2"
                >
                    {#if authUser}
                        <Link
                            href="/dashboard"
                            class="w-full text-center py-2.5 rounded-xl font-semibold text-white bg-gradient-to-r from-[#4F46E5] to-[#3B82F6]"
                        >
                            Ir a mi Taller →
                        </Link>
                    {:else}
                        <Link
                            href="/login"
                            class="w-full text-center py-2 rounded-xl text-sm font-semibold border border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-200"
                        >
                            Iniciar Sesión
                        </Link>
                        <Link
                            href="/register"
                            class="w-full text-center py-2.5 rounded-xl text-sm font-semibold text-white bg-[#4F46E5]"
                        >
                            Crear mi Taller Gratis
                        </Link>
                    {/if}
                </div>
            </div>
        {/if}
    </header>

    <!-- ========================================================================= -->
    <!-- 2. HERO BANNER DE ALTA CONVERSIÓN CON FONDO ATMOSFÉRICO                  -->
    <!-- ========================================================================= -->
    <section
        id="producto"
        class="relative pt-32 sm:pt-40 pb-20 sm:pb-28 overflow-hidden"
    >
        <!-- Fondo Atmosférico con Cuadrícula y Auroras Difuminadas -->
        <div class="absolute inset-0 pointer-events-none -z-10">
            <!-- Cuadrícula Técnica Suave -->
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:32px_32px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"
            ></div>

            <!-- Auroras Difusas (#4F46E5 en Azul Eléctrico y #06B6D4 en Cian Vibrante) -->
            <div
                class="absolute -top-32 left-1/2 -translate-x-3/4 w-[500px] h-[500px] bg-[#4F46E5]/20 dark:bg-[#4F46E5]/25 rounded-full blur-3xl opacity-75"
            ></div>
            <div
                class="absolute top-10 left-1/2 -translate-x-1/4 w-[450px] h-[450px] bg-[#06B6D4]/20 dark:bg-[#06B6D4]/25 rounded-full blur-3xl opacity-70"
            ></div>
        </div>

        <div
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative"
        >
            <!-- Píldora Superior Animada -->
            <div
                class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-slate-100/90 dark:bg-[#151D2C]/90 border border-slate-200/80 dark:border-[#26334D] text-xs font-semibold text-slate-800 dark:text-slate-200 shadow-sm backdrop-blur-md mb-8 hover:scale-105 transition-transform duration-200 cursor-default"
            >
                <span class="relative flex h-2 w-2">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#06B6D4] opacity-75"
                    ></span>
                    <span
                        class="relative inline-flex rounded-full h-2 w-2 bg-[#06B6D4]"
                    ></span>
                </span>
                <span
                    >✨ Gestor Inteligente de Pedidos, Producción e Inventario
                    para Talleres y Pymes</span
                >
            </div>

            <!-- Título Principal Impactante -->
            <h1
                class="text-4xl sm:text-6xl lg:text-7xl font-bold tracking-tight text-slate-900 dark:text-white max-w-5xl mx-auto leading-[1.12]"
            >
                La app fácil para organizar, controlar y
                <span
                    class="bg-gradient-to-r from-[#4F46E5] via-[#3B82F6] to-[#06B6D4] bg-clip-text text-transparent"
                >
                    despachar tus pedidos sin complicaciones.
                </span>
            </h1>

            <!-- Subtítulo persuasivo -->
            <p
                class="mt-6 sm:mt-8 text-lg sm:text-xl text-[#64748B] dark:text-slate-300 max-w-3xl mx-auto font-normal leading-relaxed"
            >
                Controla desde la cotización y el abono inicial hasta cada etapa
                del tablero Kanban, el consumo exacto de materia prima (BOM) y
                la fecha real de entrega en un solo lugar.
            </p>

            <!-- Doble CTA Comercial -->
            <div
                class="mt-9 sm:mt-11 flex flex-col sm:flex-row items-center justify-center gap-4"
            >
                {#if authUser}
                    <Link
                        href="/dashboard"
                        class="w-full sm:w-auto px-8 py-4 rounded-xl text-base font-bold text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#3B82F6] hover:opacity-95 shadow-xl shadow-[#4F46E5]/30 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 flex items-center justify-center gap-2 group"
                    >
                        <span>Ir a mi Panel de Control</span>
                        <span
                            class="transition-transform group-hover:translate-x-1 font-mono text-lg"
                            >→</span
                        >
                    </Link>
                {:else}
                    <Link
                        href="/register"
                        class="w-full sm:w-auto px-8 py-4 rounded-xl text-base font-bold text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#3B82F6] hover:opacity-95 shadow-xl shadow-[#4F46E5]/30 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 flex items-center justify-center gap-2 group"
                    >
                        <span>Empezar Ahora — Prueba Gratis</span>
                        <span
                            class="transition-transform group-hover:translate-x-1 font-mono text-lg"
                            >→</span
                        >
                    </Link>
                {/if}

                <button
                    type="button"
                    onclick={() => scrollToSection("escaparate-demo")}
                    class="w-full sm:w-auto px-7 py-4 rounded-xl text-base font-semibold text-slate-700 dark:text-slate-200 bg-white/90 dark:bg-[#151D2C]/90 border border-slate-200/90 dark:border-[#26334D] hover:bg-slate-100 dark:hover:bg-[#1f293d] shadow-sm transition-all flex items-center justify-center gap-2 cursor-pointer"
                >
                    <span>Explorar Demo en Vivo</span>
                    <span class="text-xs text-[#06B6D4]">▼</span>
                </button>
            </div>

            <!-- Tres micro-garantías rápidas -->
            <div
                class="mt-6 flex flex-wrap items-center justify-center gap-y-2 gap-x-6 text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400"
            >
                <span class="flex items-center gap-1.5">
                    <span class="text-emerald-500 font-bold">✓</span> Control de
                    Abonos y Saldos
                </span>
                <span
                    class="text-slate-300 dark:text-slate-700 hidden sm:inline"
                    >•</span
                >
                <span class="flex items-center gap-1.5">
                    <span class="text-[#06B6D4] font-bold">✓</span> Tablero Kanban
                    por Flujos
                </span>
                <span
                    class="text-slate-300 dark:text-slate-700 hidden sm:inline"
                    >•</span
                >
                <span class="flex items-center gap-1.5">
                    <span class="text-[#4F46E5] font-bold">✓</span> Recetas y Códigos
                    de Barras
                </span>
            </div>

            <!-- ================================================================= -->
            <!-- 3. ESCAPARATE INTERACTIVO PARA LAS 2 CAPTURAS REALES               -->
            <!-- ================================================================= -->
            <div
                id="escaparate-demo"
                class="mt-14 sm:mt-20 max-w-6xl mx-auto relative text-left"
            >
                <!-- Barra de Control del Escaparate -->
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 mb-4 bg-white/70 dark:bg-[#151D2C]/70 backdrop-blur-xl p-2 sm:p-2.5 rounded-2xl border border-slate-200/80 dark:border-[#26334D] shadow-sm"
                >
                    <div class="flex items-center gap-1.5">
                        <span
                            class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 px-2"
                        >
                            Explorar Interfaz Real:
                        </span>
                    </div>

                    <!-- Selector de Modos de Vista -->
                    <div
                        class="flex items-center gap-1 bg-slate-100 dark:bg-[#0B0F17] p-1 rounded-xl w-full sm:w-auto justify-center overflow-x-auto"
                    >
                        <button
                            type="button"
                            onclick={() => (showcaseMode = "split")}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode ===
                            'split'
                                ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm'
                                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Desliza para comparar Modo Claro y Modo Oscuro"
                        >
                            <span>🌗</span>
                            <span>Comparador (Split)</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = "layered")}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode ===
                            'layered'
                                ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm'
                                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Composición en capas superpuestas"
                        >
                            <span>✨</span>
                            <span>Vista en Capas</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = "light")}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode ===
                            'light'
                                ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm'
                                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Ver únicamente la versión en Modo Claro"
                        >
                            <span>☀️</span>
                            <span>Solo Claro</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = "dark")}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode ===
                            'dark'
                                ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm'
                                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Ver únicamente la versión en Modo Oscuro"
                        >
                            <span>🌙</span>
                            <span>Solo Oscuro</span>
                        </button>
                    </div>
                </div>

                <!-- Contenedor Principal del Marco de Navegador Mac/SaaS -->
                <div class="relative group">
                    <!-- Tarjeta Flotante 1 (Arriba a la Derecha): Pedido #3 Pagado -->
                    <div
                        class="floating-badge-top absolute -top-5 sm:-top-7 -right-3 sm:-right-6 z-40 hidden md:flex items-center gap-3 p-3.5 bg-white/95 dark:bg-[#151D2C]/95 backdrop-blur-xl border border-slate-200/90 dark:border-[#26334D] rounded-2xl shadow-2xl shadow-[#4F46E5]/15 pointer-events-auto"
                    >
                        <div
                            class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-lg shrink-0"
                        >
                            📦
                        </div>
                        <div class="text-left">
                            <div
                                class="text-xs font-bold text-slate-900 dark:text-white flex items-center gap-1.5"
                            >
                                <span>Pedido #3</span>
                                <span
                                    class="text-slate-400 font-normal truncate max-w-[150px]"
                                    >• María F. Gómez</span
                                >
                            </div>
                            <div
                                class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-2 mt-0.5"
                            >
                                <span>✓ Pagado · $58</span>
                                <span
                                    class="px-1.5 py-0.5 rounded bg-emerald-500/15 text-[10px] font-bold"
                                    >Listo para Entrega</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta Flotante 2 (Abajo a la Izquierda): Capacidad del Taller -->
                    <div
                        class="floating-badge-bottom absolute -bottom-5 sm:-bottom-7 -left-3 sm:-left-6 z-40 hidden md:flex items-center gap-3 p-3.5 bg-white/95 dark:bg-[#151D2C]/95 backdrop-blur-xl border border-slate-200/90 dark:border-[#26334D] rounded-2xl shadow-2xl shadow-[#06B6D4]/15 pointer-events-auto"
                    >
                        <div
                            class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg shrink-0"
                        >
                            ⚡
                        </div>
                        <div class="text-left">
                            <div
                                class="text-xs font-bold text-slate-900 dark:text-white flex items-center gap-2"
                            >
                                <span>Capacidad en Tiempo Real</span>
                                <span class="relative flex h-2 w-2">
                                    <span
                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#06B6D4] opacity-75"
                                    ></span>
                                    <span
                                        class="relative inline-flex rounded-full h-2 w-2 bg-[#06B6D4]"
                                    ></span>
                                </span>
                            </div>
                            <div
                                class="text-[11px] text-slate-500 dark:text-slate-300 mt-0.5"
                            >
                                2 Pedidos en cola • 100% Trazabilidad Kanban
                            </div>
                        </div>
                    </div>

                    <!-- Marco de Navegador Estilo macOS / SaaS para Split, Solo Claro y Solo Oscuro -->
                    {#if showcaseMode !== "layered"}
                        <div
                            class="rounded-2xl sm:rounded-3xl border border-slate-200/80 dark:border-[#26334D] bg-white dark:bg-[#151D2C] shadow-2xl shadow-slate-900/10 dark:shadow-black/50 overflow-hidden ring-1 ring-slate-900/5"
                        >
                            <!-- Barra Superior de Navegador con 3 Puntos y URL -->
                            <div
                                class="h-11 sm:h-12 bg-slate-100/90 dark:bg-[#0B0F17]/90 px-4 flex items-center justify-between border-b border-slate-200/80 dark:border-[#26334D]"
                            >
                                <!-- Puntos Mac -->
                                <div class="flex items-center gap-2 w-20">
                                    <span
                                        class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"
                                    ></span>
                                    <span
                                        class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"
                                    ></span>
                                    <span
                                        class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"
                                    ></span>
                                </div>

                                <!-- Barra de URL Simulada -->
                                <div
                                    class="flex-1 max-w-sm sm:max-w-md mx-auto"
                                >
                                    <div
                                        class="bg-white dark:bg-[#151D2C] rounded-lg px-3 py-1 flex items-center justify-center gap-2 text-xs font-mono text-slate-500 dark:text-slate-400 border border-slate-200/60 dark:border-[#26334D]/70 shadow-inner"
                                    >
                                        <svg
                                            class="w-3.5 h-3.5 text-emerald-500 shrink-0"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                                            />
                                        </svg>
                                        <span class="truncate"
                                            >https://app.gippyme.com/dashboard</span
                                        >
                                        <span
                                            class="text-slate-300 dark:text-slate-600 hidden sm:inline"
                                            >↻</span
                                        >
                                    </div>
                                </div>

                                <!-- Estado del Taller en la barra -->
                                <div class="w-20 text-right">
                                    <span
                                        class="text-[10px] font-bold text-slate-500 dark:text-slate-400 bg-slate-200/60 dark:bg-[#1f293d] px-2 py-0.5 rounded-full uppercase tracking-wider hidden sm:inline"
                                    >
                                        En Vivo
                                    </span>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- ÁREA VISUAL SEGÚN EL MODO SELECCIONADO                    -->
                            <!-- ========================================================= -->

                            <!-- MODO 1: COMPARADOR INTERACTIVO SPLIT (Por Defecto) -->
                            {#if showcaseMode === "split"}
                                <div
                                    bind:this={sliderContainerRef}
                                    onpointerdown={onPointerDown}
                                    role="presentation"
                                    class="relative select-none overflow-hidden cursor-ew-resize bg-slate-950 aspect-[16/9] w-full"
                                >
                                    <!-- Imagen Fondo: MODO OSCURO -->
                                    {#if !darkImgFailed}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="Panel de Pedidos gipPyme en Modo Oscuro"
                                            onerror={() =>
                                                (darkImgFailed = true)}
                                            class="w-full h-full object-cover sm:object-contain pointer-events-none select-none block"
                                        />
                                    {:else}
                                        <!-- Mockup Fallback Modo Oscuro -->
                                        <div
                                            class="w-full h-full p-6 bg-[#0B0F17] text-white flex flex-col justify-between"
                                        >
                                            <div
                                                class="flex justify-between items-center border-b border-[#26334D] pb-3"
                                            >
                                                <div class="font-bold text-lg">
                                                    Panel de Pedidos • Modo
                                                    Oscuro
                                                </div>
                                                <div
                                                    class="text-xs bg-[#4F46E5] px-2 py-1 rounded"
                                                >
                                                    3 Pedidos Activos
                                                </div>
                                            </div>
                                            <div
                                                class="grid grid-cols-4 gap-3 my-4"
                                            >
                                                <div
                                                    class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]"
                                                >
                                                    Total: 3
                                                </div>
                                                <div
                                                    class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]"
                                                >
                                                    Ingresos: $775
                                                </div>
                                                <div
                                                    class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]"
                                                >
                                                    Pendientes: 2
                                                </div>
                                                <div
                                                    class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]"
                                                >
                                                    Clientes: 3
                                                </div>
                                            </div>
                                            <div class="text-xs text-slate-400">
                                                Captura real cargada desde
                                                /images/landing/dashboard-dark.png
                                            </div>
                                        </div>
                                    {/if}

                                    <!-- Imagen Superpuesta Recortada Dinámicamente: MODO CLARO -->
                                    <div
                                        class="absolute inset-0 pointer-events-none overflow-hidden"
                                        style="clip-path: inset(0 {100 -
                                            sliderPos}% 0 0);"
                                    >
                                        {#if !lightImgFailed}
                                            <img
                                                src="/images/landing/dashboard-light.png"
                                                alt="Panel de Pedidos gipPyme en Modo Claro"
                                                onerror={() =>
                                                    (lightImgFailed = true)}
                                                class="w-full h-full object-cover sm:object-contain pointer-events-none select-none block"
                                            />
                                        {:else}
                                            <!-- Mockup Fallback Modo Claro -->
                                            <div
                                                class="w-full h-full p-6 bg-[#F8FAFC] text-slate-800 flex flex-col justify-between"
                                            >
                                                <div
                                                    class="flex justify-between items-center border-b border-slate-200 pb-3"
                                                >
                                                    <div
                                                        class="font-bold text-lg text-slate-900"
                                                    >
                                                        Panel de Pedidos • Modo
                                                        Claro
                                                    </div>
                                                    <div
                                                        class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded"
                                                    >
                                                        3 Pedidos Activos
                                                    </div>
                                                </div>
                                                <div
                                                    class="grid grid-cols-4 gap-3 my-4"
                                                >
                                                    <div
                                                        class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm"
                                                    >
                                                        Total: 3
                                                    </div>
                                                    <div
                                                        class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm"
                                                    >
                                                        Ingresos: $775
                                                    </div>
                                                    <div
                                                        class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm"
                                                    >
                                                        Pendientes: 2
                                                    </div>
                                                    <div
                                                        class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm"
                                                    >
                                                        Clientes: 3
                                                    </div>
                                                </div>
                                                <div
                                                    class="text-xs text-slate-500"
                                                >
                                                    Captura real cargada desde
                                                    /images/landing/dashboard-light.png
                                                </div>
                                            </div>
                                        {/if}
                                    </div>

                                    <!-- Línea Divisoria y Tirador de Arrastre -->
                                    <div
                                        class="absolute top-0 bottom-0 pointer-events-none z-30"
                                        style="left: {sliderPos}%;"
                                    >
                                        <!-- Línea vertical luminosa -->
                                        <div
                                            class="w-0.5 h-full bg-white dark:bg-[#4F46E5] shadow-[0_0_12px_rgba(79,70,229,0.9)] -translate-x-1/2"
                                        ></div>

                                        <!-- Botón circular central con flechas opuestas -->
                                        <div
                                            class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-10 sm:w-11 h-10 sm:h-11 rounded-full bg-[#4F46E5] text-white shadow-xl shadow-[#4F46E5]/60 flex items-center justify-center font-bold text-sm border-2 border-white dark:border-[#0B0F17] hover:scale-110 active:scale-95 transition-transform"
                                        >
                                            <span
                                                class="select-none tracking-tighter text-xs sm:text-sm font-sans"
                                                >◀ ▶</span
                                            >
                                        </div>
                                    </div>

                                    <!-- Etiquetas Flotantes en el Comparador -->
                                    <div
                                        class="absolute top-3.5 left-3.5 z-20 pointer-events-none"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-white/90 text-slate-800 shadow-md backdrop-blur-md border border-slate-200/80"
                                        >
                                            ☀️ Modo Claro
                                        </span>
                                    </div>
                                    <div
                                        class="absolute top-3.5 right-3.5 z-20 pointer-events-none"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-[#0B0F17]/90 text-white shadow-md backdrop-blur-md border border-[#26334D]/80"
                                        >
                                            🌙 Modo Oscuro
                                        </span>
                                    </div>

                                    <!-- Input Range transparente para máxima accesibilidad y soporte táctil fluido -->
                                    <input
                                        type="range"
                                        min="2"
                                        max="98"
                                        bind:value={sliderPos}
                                        class="absolute inset-0 w-full h-full opacity-0 cursor-ew-resize z-20"
                                        aria-label="Arrastra para comparar Modo Claro y Modo Oscuro"
                                    />
                                </div>

                                <!-- MODO 3A: SOLO CLARO A PANTALLA COMPLETA -->
                            {:else if showcaseMode === "light"}
                                <div
                                    class="relative bg-white aspect-[16/9] w-full"
                                >
                                    <img
                                        src="/images/landing/dashboard-light.png"
                                        alt="Panel de Pedidos en Modo Claro Completo"
                                        class="w-full h-full object-cover sm:object-contain block select-none"
                                    />
                                    <div class="absolute bottom-4 right-4">
                                        <span
                                            class="px-3 py-1 rounded-lg text-xs font-bold bg-white/95 text-slate-800 shadow-md border border-slate-200/80"
                                        >
                                            ☀️ Vista 100% Modo Claro
                                        </span>
                                    </div>
                                </div>

                                <!-- MODO 3B: SOLO OSCURO A PANTALLA COMPLETA -->
                            {:else if showcaseMode === "dark"}
                                <div
                                    class="relative bg-[#0B0F17] aspect-[16/9] w-full"
                                >
                                    <img
                                        src="/images/landing/dashboard-dark.png"
                                        alt="Panel de Pedidos en Modo Oscuro Completo"
                                        class="w-full h-full object-cover sm:object-contain block select-none"
                                    />
                                    <div class="absolute bottom-4 right-4">
                                        <span
                                            class="px-3 py-1 rounded-lg text-xs font-bold bg-[#151D2C]/95 text-white shadow-md border border-[#26334D]"
                                        >
                                            🌙 Vista 100% Modo Oscuro
                                        </span>
                                    </div>
                                </div>
                            {/if}
                        </div>

                        <!-- MODO 2: VISTA DUAL EN CAPAS CON DESFASE DIAGONAL AMPLIO -->
                    {:else}
                        <div class="relative w-full pb-20 sm:pb-32 pt-4">
                            <!-- CAPA TRASERA (Back Layer — 78% de ancho, arriba a la izquierda) -->
                            <div
                                class="relative z-10 w-[85%] sm:w-[78%] mr-auto rounded-2xl overflow-hidden transition-all duration-500 cursor-pointer opacity-90 hover:opacity-100 hover:-translate-y-1.5 ring-2 ring-[#4F46E5]/40 shadow-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]"
                                onclick={toggleLayers}
                                role="button"
                                tabindex="0"
                                onkeydown={(e) =>
                                    (e.key === "Enter" || e.key === " ") &&
                                    toggleLayers()}
                                title="Clic para traer esta capa al frente"
                            >
                                <!-- Barra Superior de Ventana Capa Trasera -->
                                <div
                                    class="h-10 sm:h-11 px-3 sm:px-4 flex items-center justify-between border-b {activeLayer ===
                                    'light'
                                        ? 'bg-[#0B0F17] border-[#26334D] text-slate-200'
                                        : 'bg-slate-100 border-slate-200 text-slate-700'}"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="w-2.5 h-2.5 rounded-full bg-[#EF4444] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="w-2.5 h-2.5 rounded-full bg-[#F59E0B] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="w-2.5 h-2.5 rounded-full bg-[#10B981] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="text-xs font-semibold ml-1.5 flex items-center gap-1.5 {activeLayer ===
                                            'light'
                                                ? 'text-slate-300'
                                                : 'text-slate-700'}"
                                        >
                                            {#if activeLayer === "light"}
                                                <span>🌙 Modo Oscuro</span>
                                            {:else}
                                                <span>☀️ Modo Claro</span>
                                            {/if}
                                            <span
                                                class="hidden sm:inline text-[11px] opacity-60"
                                                >• Capa Trasera</span
                                            >
                                        </span>
                                    </div>

                                    <button
                                        type="button"
                                        onclick={(e) => {
                                            e.stopPropagation();
                                            toggleLayers();
                                        }}
                                        class="bg-[#4F46E5] hover:bg-[#4338CA] text-white text-xs px-3 py-1 rounded-full font-semibold shadow transition-all hover:scale-105 active:scale-95"
                                    >
                                        👆 Clic para traer al frente
                                    </button>
                                </div>

                                <!-- Imagen de la Capa Trasera -->
                                <div
                                    class="relative bg-slate-900/5 dark:bg-black/20"
                                >
                                    {#if activeLayer === "light"}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="gipPyme Dashboard Modo Oscuro (Capa Trasera)"
                                            class="w-full h-auto block select-none pointer-events-none"
                                        />
                                    {:else}
                                        <img
                                            src="/images/landing/dashboard-light.png"
                                            alt="gipPyme Dashboard Modo Claro (Capa Trasera)"
                                            class="w-full h-auto block select-none pointer-events-none"
                                        />
                                    {/if}
                                </div>
                            </div>

                            <!-- CAPA FRONTAL (Front Layer — 80% de ancho, desplazada abajo a la derecha) -->
                            <div
                                class="relative z-20 w-[88%] sm:w-[80%] ml-auto -mt-28 sm:-mt-52 lg:-mt-64 rounded-2xl overflow-hidden transition-all duration-500 shadow-[0_30px_80px_-15px_rgba(15,23,42,0.65)] ring-2 ring-[#06B6D4]/70 bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]"
                            >
                                <!-- Barra Superior de Ventana Capa Frontal -->
                                <div
                                    class="h-10 sm:h-12 px-3 sm:px-4 flex items-center justify-between border-b {activeLayer ===
                                    'light'
                                        ? 'bg-slate-100/90 border-slate-200 text-slate-800'
                                        : 'bg-[#0B0F17]/90 border-[#26334D] text-white'}"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"
                                        ></span>
                                        <span
                                            class="text-xs sm:text-sm font-bold ml-1.5 flex items-center gap-1.5 {activeLayer ===
                                            'light'
                                                ? 'text-slate-800'
                                                : 'text-white'}"
                                        >
                                            {#if activeLayer === "light"}
                                                <span>☀️ Modo Claro</span>
                                            {:else}
                                                <span>🌙 Modo Oscuro</span>
                                            {/if}
                                            <span
                                                class="text-xs font-semibold text-[#06B6D4] hidden sm:inline"
                                                >(Al Frente)</span
                                            >
                                        </span>
                                    </div>

                                    <!-- URL simulada central en pantallas medianas/grandes -->
                                    <div
                                        class="hidden md:flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-mono {activeLayer ===
                                        'light'
                                            ? 'bg-white border-slate-200 text-slate-600'
                                            : 'bg-[#151D2C] border-[#26334D] text-slate-300'} border shadow-inner"
                                    >
                                        <span class="text-emerald-500 text-xs"
                                            >🔒</span
                                        >
                                        <span
                                            >https://app.gippyme.com/dashboard</span
                                        >
                                    </div>

                                    <!-- Botón Rápido Invertir Capas -->
                                    <button
                                        type="button"
                                        onclick={toggleLayers}
                                        class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-1 sm:py-1.5 rounded-full text-xs font-semibold bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-md shadow-[#4F46E5]/30 hover:scale-105 active:scale-95 transition-all cursor-pointer"
                                    >
                                        <span>🔄 Invertir Capas</span>
                                    </button>
                                </div>

                                <!-- Imagen de la Capa Frontal -->
                                <div
                                    class="relative bg-slate-900/5 dark:bg-black/20"
                                >
                                    {#if activeLayer === "light"}
                                        <img
                                            src="/images/landing/dashboard-light.png"
                                            alt="gipPyme Dashboard Modo Claro (Capa Frontal)"
                                            class="w-full h-auto block select-none"
                                        />
                                    {:else}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="gipPyme Dashboard Modo Oscuro (Capa Frontal)"
                                            class="w-full h-auto block select-none"
                                        />
                                    {/if}
                                </div>
                            </div>
                        </div>
                    {/if}
                </div>

                <!-- Resumen de Navegación del Escaparate -->
                <p
                    class="mt-4 text-center text-xs text-[#64748B] dark:text-slate-400"
                >
                    {#if showcaseMode === "layered"}
                        💡 <strong>Tip interactivo:</strong> Haz clic en la
                        ventana trasera o en el botón
                        <strong>"🔄 Invertir Capas"</strong> para alternar qué tema
                        está en primer plano.
                    {:else if showcaseMode === "split"}
                        💡 <strong>Tip interactivo:</strong> En el modo comparador,
                        arrastra el control ◀ ▶ horizontalmente para ver la perfecta
                        transición entre paleta clara y paleta oscura.
                    {:else}
                        💡 <strong>Tip interactivo:</strong> Puedes alternar
                        entre <strong>Solo Claro</strong>,
                        <strong>Solo Oscuro</strong>
                        o el <strong>Comparador</strong> desde la barra superior.
                    {/if}
                </p>
            </div>
        </div>
    </section>

    <!-- ========================================================================= -->
    <!-- 4. EL ECOSISTEMA COMPLETO: 5 MÓDULOS CONECTADOS (#modulos)                 -->
    <!-- ========================================================================= -->
    <section
        id="modulos"
        class="py-20 sm:py-28 border-t border-slate-200/70 dark:border-[#26334D]/70 bg-white/60 dark:bg-[#0B0F17]/60 backdrop-blur-sm relative"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Cabecera de la Sección -->
            <div class="text-center max-w-3xl mx-auto">
                <div
                    class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#4F46E5]/10 dark:bg-[#4F46E5]/20 border border-[#4F46E5]/30 text-xs font-bold text-[#4F46E5] dark:text-[#818CF8] uppercase tracking-wider mb-4"
                >
                    <span>🧩 MÓDULOS PRINCIPALES</span>
                </div>
                <h2
                    class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-slate-900 dark:text-white leading-tight"
                >
                    Todo lo que tu taller necesita, conectado en un mismo flujo
                </h2>
                <p
                    class="mt-4 text-base sm:text-lg text-[#64748B] dark:text-slate-300 leading-relaxed"
                >
                    Cinco herramientas diseñadas específicamente para producción
                    bajo pedido, sincronizadas automáticamente sin hojas de
                    cálculo dispersas ni mensajes perdidos.
                </p>
            </div>

            <!-- Selector Principal: 5 Tarjetas de Flujo Conectadas como Pestañas -->
            <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                {#each flowSteps as step, idx}
                    <button
                        type="button"
                        onclick={() => selectModule(idx)}
                        class="p-3.5 sm:p-4 rounded-2xl text-left transition-all duration-200 flex flex-col justify-between cursor-pointer relative overflow-hidden border-2 {activeModuleIndex === idx
                            ? 'border-[#4F46E5] bg-[#4F46E5]/[0.04] dark:bg-[#4F46E5]/15 shadow-md shadow-[#4F46E5]/15'
                            : 'border-slate-200/80 dark:border-[#26334D] bg-white dark:bg-[#151D2C] hover:border-slate-300 dark:hover:border-slate-600'}"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-[#0B0F17] flex items-center justify-center text-base border border-slate-200/80 dark:border-[#26334D] shrink-0">
                                    {step.icon}
                                </span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full {activeModuleIndex === idx ? 'bg-[#4F46E5] text-white' : 'bg-slate-100 dark:bg-[#0B0F17] text-slate-500 dark:text-slate-400'}">
                                    Paso {step.stepNumber}
                                </span>
                            </div>
                            <h4 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white transition-colors truncate">
                                {step.title}
                            </h4>
                            <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed line-clamp-2">
                                {step.description}
                            </p>
                        </div>

                        <!-- Indicador inferior activo (sin saltos de altura) -->
                        <div class="mt-3 h-1 w-full rounded-full transition-colors {activeModuleIndex === idx ? 'bg-[#4F46E5]' : 'bg-transparent'}"></div>
                    </button>
                {/each}
            </div>

            <!-- Panel Principal Dividido (Explicación Estable + Escaparate Visual) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch mt-8">
                <!-- Columna Izquierda (lg:col-span-5 — Altura fija/estable) -->
                <div class="lg:col-span-5 lg:min-h-[430px] flex flex-col justify-between text-left">
                    <div>
                        <!-- Badge del Módulo -->
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold tracking-wider uppercase bg-[#4F46E5]/10 dark:bg-[#4F46E5]/20 text-[#4F46E5] dark:text-[#818CF8] border border-[#4F46E5]/20">
                            {currentModule.badge}
                        </span>

                        <!-- Título Grande -->
                        <h3 class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white mt-3.5 leading-snug">
                            {currentModule.title}
                        </h3>

                        <!-- Descripción Persuasiva -->
                        <p class="mt-4 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed">
                            {currentModule.description}
                        </p>

                        <!-- 3 Puntos Clave con Iconos de Check en #06B6D4 -->
                        <ul class="mt-6 space-y-3.5">
                            {#each currentModule.points as pt}
                                <li class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-200 leading-relaxed">
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold mt-0.5 shadow-sm">
                                        ✓
                                    </span>
                                    <span>{pt}</span>
                                </li>
                            {/each}
                        </ul>
                    </div>

                    <!-- Botones de Navegación y Enlace CTA -->
                    <div class="mt-8 pt-6 border-t border-slate-200/80 dark:border-[#26334D] flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={prevModule}
                                class="px-3.5 py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#1f293d] transition-all flex items-center gap-1.5 shadow-sm cursor-pointer"
                                title="Ver módulo anterior"
                            >
                                <span>←</span>
                                <span class="hidden sm:inline">Módulo Anterior</span>
                            </button>
                            <button
                                type="button"
                                onclick={nextModule}
                                class="px-3.5 py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#1f293d] transition-all flex items-center gap-1.5 shadow-sm cursor-pointer"
                                title="Ver siguiente módulo"
                            >
                                <span class="hidden sm:inline">Siguiente Módulo</span>
                                <span>→</span>
                            </button>
                        </div>

                        <Link
                            href="/register"
                            class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-[#4F46E5] dark:text-[#06B6D4] hover:underline"
                        >
                            <span>Probar este módulo gratis</span>
                            <span>→</span>
                        </Link>
                    </div>
                </div>

                <!-- Columna Derecha (lg:col-span-7 — Escaparate Visual con Altura Fija) -->
                <div class="lg:col-span-7">
                    <div class="relative rounded-2xl sm:rounded-3xl border border-slate-200/90 dark:border-[#26334D] bg-white dark:bg-[#151D2C] shadow-2xl shadow-slate-900/10 dark:shadow-black/60 overflow-hidden ring-1 ring-slate-900/5 group flex flex-col">
                        
                        <!-- Barra Superior macOS (🔴 🟡 🟢 + Título + Conmutadores Vista 1/Vista 2 + Botón Zoom) -->
                        <div class="h-12 sm:h-14 bg-slate-100/90 dark:bg-[#0B0F17]/90 px-3 sm:px-4 flex items-center justify-between gap-2 border-b border-slate-200/80 dark:border-[#26334D]">
                            <!-- Izquierda: Puntos + Título de la captura -->
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <span class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"></span>
                                </div>
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-300 ml-1 sm:ml-2 truncate max-w-[130px] sm:max-w-[200px]" title={currentImage.label}>
                                    {currentImage.label}
                                </span>
                            </div>

                            <!-- Centro/Derecha: Conmutadores de sub-vista si hay > 1 imagen + Botón Zoom -->
                            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                                {#if currentModule.images.length > 1}
                                    <div class="flex items-center gap-1 bg-white dark:bg-[#151D2C] p-0.5 rounded-lg border border-slate-200 dark:border-[#26334D]">
                                        {#each currentModule.images as img, subIdx}
                                            <button
                                                type="button"
                                                onclick={() => (activeSubImageIndex = subIdx)}
                                                class="px-2 sm:px-2.5 py-1 rounded-md text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer {activeSubImageIndex === subIdx ? 'bg-[#4F46E5] text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                                            >
                                                <span>{subIdx === 0 ? '📷' : '🔍'}</span>
                                                <span class="hidden sm:inline">Vista {subIdx + 1}</span>
                                                <span class="sm:hidden">V{subIdx + 1}</span>
                                            </button>
                                        {/each}
                                    </div>
                                {/if}

                                <button
                                    type="button"
                                    onclick={() => (zoomedImage = currentImage)}
                                    class="inline-flex items-center gap-1 sm:gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#1f293d] shadow-sm transition-all cursor-pointer shrink-0"
                                    title="Ampliar captura en pantalla completa"
                                >
                                    <span>🔍</span>
                                    <span class="hidden md:inline">Ampliar</span>
                                </button>
                            </div>
                        </div>

                        <!-- Contenedor de visualización de imagen con ALTURA FIJA CONSTANTE -->
                        <div
                            class="h-[320px] sm:h-[420px] lg:h-[460px] w-full overflow-hidden bg-slate-100/80 dark:bg-[#0B0F17] flex items-center justify-center p-2 relative cursor-zoom-in"
                            onclick={() => (zoomedImage = currentImage)}
                            role="button"
                            tabindex="0"
                            onkeydown={(e) =>
                                (e.key === "Enter" || e.key === " ") &&
                                (zoomedImage = currentImage)}
                            title="Haz clic para ver la captura ampliada"
                        >
                            {#if !moduleImgErrors[currentImage.src]}
                                <img
                                    src={currentImage.src}
                                    alt={currentImage.label}
                                    onerror={() => (moduleImgErrors[currentImage.src] = true)}
                                    class="w-full h-full object-contain object-top transition-opacity duration-200"
                                />
                            {:else}
                                <!-- Respaldo visual limpio si la imagen no se encuentra -->
                                <div class="w-full h-full flex flex-col items-center justify-center p-8 text-center bg-gradient-to-br from-slate-100 to-slate-200 dark:from-[#151D2C] dark:to-[#0B0F17]">
                                    <div class="w-16 h-16 rounded-2xl bg-[#4F46E5]/10 text-[#4F46E5] dark:text-[#06B6D4] flex items-center justify-center text-3xl mb-3 shadow-inner">
                                        🖼️
                                    </div>
                                    <div class="font-bold text-slate-800 dark:text-white text-base">
                                        {currentImage.label}
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm">
                                        {currentImage.caption}
                                    </p>
                                </div>
                            {/if}

                            <!-- Caption inferior sutil -->
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent p-3 sm:p-4 pointer-events-none text-white">
                                <p class="text-xs sm:text-sm font-medium drop-shadow text-slate-100 line-clamp-1">
                                    {currentImage.caption}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================================================= -->
    <!-- MODAL LIGHTBOX / ZOOM DE CAPTURA A PANTALLA COMPLETA                     -->
    <!-- ========================================================================= -->
    {#if zoomedImage}
        <div
            class="fixed inset-0 z-50 bg-slate-950/90 backdrop-blur-md flex items-center justify-center p-4 sm:p-8 animate-fade-in"
            onclick={() => (zoomedImage = null)}
            onkeydown={(e) => (e.key === "Escape" || e.key === "Enter") && (zoomedImage = null)}
            role="dialog"
            aria-modal="true"
            aria-label="Captura en pantalla completa"
            tabindex="-1"
        >
            <div
                class="relative max-w-6xl w-full bg-[#151D2C] border border-[#26334D] rounded-3xl overflow-hidden shadow-2xl flex flex-col"
                onclick={(e) => e.stopPropagation()}
                role="presentation"
            >
                <!-- Barra Superior del Modal -->
                <div
                    class="px-5 sm:px-6 py-3.5 sm:py-4 border-b border-[#26334D] flex items-center justify-between bg-[#0B0F17]"
                >
                    <div class="flex items-center gap-3">
                        <span class="w-3 h-3 rounded-full bg-[#EF4444]"></span>
                        <span class="w-3 h-3 rounded-full bg-[#F59E0B]"></span>
                        <span class="w-3 h-3 rounded-full bg-[#10B981]"></span>
                        <h4
                            class="text-sm font-bold text-white ml-2 truncate max-w-md"
                        >
                            {zoomedImage.label}
                        </h4>
                    </div>
                    <button
                        type="button"
                        onclick={() => (zoomedImage = null)}
                        class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-[#1E293B] transition-colors cursor-pointer"
                        aria-label="Cerrar modal"
                    >
                        <svg
                            class="w-5 h-5 sm:w-6 sm:h-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <!-- Imagen Grande -->
                <div
                    class="p-3 sm:p-6 bg-slate-950 flex items-center justify-center max-h-[75vh] overflow-auto"
                >
                    <img
                        src={zoomedImage.src}
                        alt={zoomedImage.label}
                        class="max-h-[70vh] w-auto max-w-full rounded-xl object-contain shadow-2xl"
                    />
                </div>

                <!-- Pie con Caption -->
                <div
                    class="px-5 sm:px-6 py-3.5 bg-[#0B0F17] border-t border-[#26334D] text-xs text-slate-300 flex flex-col sm:flex-row sm:items-center justify-between gap-2"
                >
                    <span class="font-medium text-slate-200"
                        >{zoomedImage.caption}</span
                    >
                    <span class="text-slate-500 text-[11px] shrink-0"
                        >Presiona ESC o haz clic afuera para cerrar</span
                    >
                </div>
            </div>
        </div>
    {/if}

    <!-- ========================================================================= -->
    <!-- 5. SPOTLIGHT / CENTRO DE CONTROL DINÁMICO KANBAN (#kanban)                -->
    <!-- ========================================================================= -->
    <section
        id="kanban"
        class="bg-slate-900 text-white dark:bg-[#080C14] border-y border-slate-800 py-20 px-4 sm:px-6 lg:px-8 relative overflow-hidden"
    >
        <span id="flujos" class="sr-only">Flujos y Tableros Kanban</span>

        <!-- Destellos sutiles de luz en Cian y Azul Eléctrico en las esquinas -->
        <div
            class="absolute -top-40 -left-40 w-96 h-96 bg-[#06B6D4]/15 rounded-full blur-[100px] pointer-events-none"
        ></div>
        <div
            class="absolute -bottom-40 -right-40 w-96 h-96 bg-[#3B82F6]/15 rounded-full blur-[100px] pointer-events-none"
        ></div>
        <div
            class="absolute top-1/4 left-1/2 -translate-x-1/2 w-[700px] h-[350px] bg-gradient-to-b from-[#06B6D4]/10 via-[#4F46E5]/5 to-transparent blur-[120px] pointer-events-none rounded-full"
        ></div>

        <div class="max-w-6xl mx-auto relative z-10 text-center">
            <!-- 1. TÍTULO DE IMPACTO SUPERIOR -->
            <div
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/30 text-xs font-bold text-[#06B6D4] uppercase tracking-wider mb-4 shadow-sm"
            >
                <span>⚡ CONTROL VISUAL EN TIEMPO REAL</span>
            </div>

            <h2
                class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-white leading-tight max-w-4xl mx-auto"
            >
                Un tablero Kanban que se adapta a tu taller, y no al revés
            </h2>

            <p
                class="mt-4 text-base sm:text-lg text-slate-300 leading-relaxed max-w-3xl mx-auto"
            >
                Crea tantos flujos y estados como necesites, arrastra tarjetas entre columnas y mantén a todo tu equipo sincronizado sin fricción.
            </p>

            <!-- 2. SELECTOR FLOTANTE MINIMALISTA ESTILO DOCK / PÍLDORA -->
            <div class="mt-8 sm:mt-10 flex justify-center">
                <nav
                    class="inline-flex flex-wrap items-center justify-center p-1.5 rounded-full bg-slate-800/90 dark:bg-[#151D2C]/90 border border-slate-700/80 dark:border-[#26334D] shadow-2xl shadow-black/40 backdrop-blur-xl gap-1 sm:gap-1.5"
                    aria-label="Selector de Vistas Kanban"
                >
                    {#each kanbanTabs as tab, idx}
                        <button
                            type="button"
                            onclick={() => selectKanbanTab(idx)}
                            class="px-4 sm:px-5 py-2 sm:py-2.5 rounded-full text-xs sm:text-sm font-semibold transition-all duration-300 flex items-center gap-2 cursor-pointer select-none {activeKanbanTab === idx
                                ? 'bg-gradient-to-r from-[#06B6D4] to-[#3B82F6] text-white shadow-lg shadow-[#06B6D4]/30 font-bold scale-[1.02]'
                                : 'text-slate-400 hover:text-white hover:bg-slate-700/50 dark:hover:bg-[#1E293B]/70'}"
                        >
                            <span class="text-base sm:text-lg leading-none">{tab.icon}</span>
                            <span>{tab.dockLabel}</span>
                        </button>
                    {/each}
                </nav>
            </div>

            <!-- 3. VENTANA PRINCIPAL CENTRALIZADA (MARCO DE CRISTAL OSCURO PULIDO) -->
            <div class="mt-8 sm:mt-10">
                <div
                    class="relative bg-[#151D2C]/90 border border-[#26334D] shadow-[0_25px_60px_-15px_rgba(6,182,212,0.15)] rounded-2xl p-2 sm:p-3 backdrop-blur-xl text-left"
                >
                    <!-- Barra de ventana estilo macOS / SaaS -->
                    <div
                        class="h-10 sm:h-12 px-3 sm:px-4 bg-[#0B0F17]/80 rounded-t-xl border-b border-[#26334D] flex items-center justify-between gap-3"
                    >
                        <!-- Puntos macOS y Título de la vista -->
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"></span>
                                <span class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"></span>
                                <span class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"></span>
                            </div>
                            <span class="text-xs sm:text-sm font-bold text-slate-200 ml-1 truncate" title={currentKanban.image.label}>
                                {currentKanban.image.label}
                            </span>
                        </div>

                        <!-- Indicador en tiempo real + Botón Zoom -->
                        <div class="flex items-center gap-2 shrink-0">
                            <span
                                class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"
                            >
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Sincronizado en Vivo
                            </span>

                            <button
                                type="button"
                                onclick={() => (zoomedImage = currentKanban.image)}
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold bg-[#1E293B] hover:bg-[#26334D] border border-slate-700/80 text-slate-200 shadow-sm transition-all cursor-pointer"
                                title="Ampliar captura en pantalla completa"
                            >
                                <span>🔍</span>
                                <span class="hidden sm:inline">Ampliar</span>
                            </button>
                        </div>
                    </div>

                    <!-- Contenedor de Imagen con Transición Fluida -->
                    <div
                        class="h-[340px] sm:h-[480px] lg:h-[540px] w-full overflow-hidden bg-[#0B0F17] rounded-b-xl flex items-center justify-center p-2 relative cursor-zoom-in group"
                        onclick={() => (zoomedImage = currentKanban.image)}
                        role="button"
                        tabindex="0"
                        onkeydown={(e) =>
                            (e.key === "Enter" || e.key === " ") &&
                            (zoomedImage = currentKanban.image)}
                        title="Haz clic para ver la captura ampliada"
                    >
                        {#if !kanbanImgErrors[currentKanban.image.src]}
                            <img
                                src={currentKanban.image.src}
                                alt={currentKanban.image.label}
                                onerror={() => (kanbanImgErrors[currentKanban.image.src] = true)}
                                class="w-full h-full object-contain object-top transition-all duration-300 group-hover:scale-[1.01]"
                            />
                        {:else}
                            <div class="w-full h-full flex flex-col items-center justify-center p-8 text-center bg-gradient-to-br from-[#151D2C] to-[#0B0F17]">
                                <div class="w-16 h-16 rounded-2xl bg-[#06B6D4]/10 text-[#06B6D4] flex items-center justify-center text-3xl mb-3 shadow-inner">
                                    📋
                                </div>
                                <div class="font-bold text-white text-base">
                                    {currentKanban.image.label}
                                </div>
                                <p class="text-xs text-slate-400 mt-1 max-w-sm">
                                    {currentKanban.image.caption}
                                </p>
                            </div>
                        {/if}

                        <!-- Caption translúcido inferior -->
                        <div
                            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent p-3.5 sm:p-4 pointer-events-none text-white rounded-b-xl flex items-center justify-between gap-4"
                        >
                            <p class="text-xs sm:text-sm font-medium drop-shadow text-slate-200 line-clamp-1">
                                {currentKanban.image.caption}
                            </p>
                            <span class="text-[11px] text-[#06B6D4] font-semibold hidden md:inline shrink-0">
                                💡 Clic para ampliar a pantalla completa
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. BARRA DE HIGHLIGHTS DINÁMICOS EN 3 COLUMNAS -->
            <div class="mt-8 sm:mt-10 grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 text-left">
                {#each currentKanban.highlights as hl}
                    <div
                        class="p-5 sm:p-6 rounded-2xl bg-slate-800/60 dark:bg-[#121927]/80 border border-slate-700/60 dark:border-[#26334D] backdrop-blur-md hover:border-[#06B6D4]/50 transition-all duration-300 group shadow-lg"
                    >
                        <div
                            class="w-10 h-10 rounded-xl bg-[#06B6D4]/10 border border-[#06B6D4]/30 text-[#06B6D4] flex items-center justify-center text-lg mb-3.5 group-hover:scale-110 transition-transform shadow-inner"
                        >
                            {hl.icon}
                        </div>
                        <h4
                            class="text-sm sm:text-base font-bold text-white group-hover:text-[#06B6D4] transition-colors"
                        >
                            {hl.title}
                        </h4>
                        <p
                            class="text-xs sm:text-sm text-slate-400 mt-1.5 leading-relaxed"
                        >
                            {hl.desc}
                        </p>
                    </div>
                {/each}
            </div>

            <!-- 5. CTA INFERIOR -->
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                <Link
                    href="/register"
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-[#06B6D4] to-[#3B82F6] hover:from-[#0891B2] hover:to-[#2563EB] shadow-lg shadow-[#06B6D4]/25 hover:shadow-[#06B6D4]/40 hover:-translate-y-0.5 active:translate-y-0 transition-all"
                >
                    <span>Probar estos Tableros en mi Taller Gratis</span>
                    <span>→</span>
                </Link>
            </div>
        </div>
    </section>

    <!-- ========================================================================= -->
    <!-- 6. SECCIÓN 4: PLANES Y PRECIOS TRANSPARENTES (#precios)                   -->
    <!-- ========================================================================= -->
    <section
        id="precios"
        class="py-20 sm:py-28 border-t border-slate-200/70 dark:border-[#26334D]/70 bg-gradient-to-b from-slate-50/60 via-white to-slate-50/80 dark:from-[#0B0F17]/80 dark:via-[#0E1522] dark:to-[#0B0F17] relative overflow-hidden"
    >
        <!-- Destellos sutiles de fondo en Azul Eléctrico y Cian -->
        <div class="absolute inset-0 pointer-events-none -z-10">
            <div
                class="absolute top-1/3 left-1/2 -translate-x-3/4 w-[500px] h-[500px] bg-[#4F46E5]/10 dark:bg-[#4F46E5]/15 rounded-full blur-3xl opacity-60"
            ></div>
            <div
                class="absolute bottom-20 left-1/2 -translate-x-1/4 w-[450px] h-[450px] bg-[#06B6D4]/10 dark:bg-[#06B6D4]/15 rounded-full blur-3xl opacity-60"
            ></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative">
            <!-- 1. Cabecera de la Sección -->
            <div class="max-w-3xl mx-auto">
                <div
                    class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#4F46E5]/10 dark:bg-[#4F46E5]/20 border border-[#4F46E5]/30 text-xs font-bold text-[#4F46E5] dark:text-[#818CF8] uppercase tracking-wider mb-4 shadow-sm"
                >
                    <span>💎 PLANES Y PRECIOS TRANSPARENTES</span>
                </div>

                <h2
                    class="font-bold tracking-tight text-3xl sm:text-4xl text-slate-900 dark:text-white leading-tight"
                >
                    Invierte en el control de tu taller, no en comisiones ocultas
                </h2>

                <p
                    class="mt-4 text-base sm:text-lg text-[#64748B] dark:text-slate-300 leading-relaxed font-normal"
                >
                    Empieza con 15 días de prueba gratis total. Sin tarjetas de crédito, sin ataduras y con acceso a todos los módulos desde el primer minuto.
                </p>
            </div>

            <!-- 2. Grid de Tarjetas de Precios Destacadas -->
            <div
                class="mt-14 sm:mt-16 max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-8 items-stretch text-left"
            >
                <!-- Opción A — Plan Flexible (Por Usuario) -->
                <div
                    class="p-8 sm:p-9 rounded-3xl bg-white dark:bg-[#151D2C] border border-slate-200/90 dark:border-[#26334D] shadow-xl shadow-slate-900/5 dark:shadow-black/40 flex flex-col justify-between relative hover:border-slate-300 dark:hover:border-slate-600 transition-all duration-300 hover:-translate-y-1"
                >
                    <div>
                        <!-- Etiqueta del Plan -->
                        <span
                            class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400"
                        >
                            Para talleres independientes y pequeños negocios
                        </span>

                        <!-- Título del Plan -->
                        <h3
                            class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white mt-1.5"
                        >
                            Plan Estándar
                        </h3>

                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">
                            La solución flexible para arrancar con orden absoluto en tus pedidos, catálogo y producción.
                        </p>

                        <!-- Precios del Plan -->
                        <div class="mt-6 pt-6 border-t border-slate-100 dark:border-[#26334D]/70">
                            <div class="flex items-baseline gap-2">
                                <span
                                    class="text-4xl sm:text-5xl font-extrabold tracking-tight text-slate-900 dark:text-white"
                                >
                                    $30.000 COP
                                </span>
                                <span class="text-sm font-bold text-slate-500 dark:text-slate-400">
                                    / mes
                                </span>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300 font-medium">
                                <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-[#1E293B] font-semibold text-slate-700 dark:text-slate-300">
                                    (Dueño)
                                </span>
                                <span class="text-slate-400 dark:text-slate-500">+</span>
                                <span class="text-slate-900 dark:text-white font-bold">
                                    $15.000 COP
                                </span>
                                <span>/ mes (Usuarios extra)</span>
                            </div>
                        </div>

                        <!-- Características Incluidas -->
                        <ul class="mt-7 space-y-3.5 text-sm text-slate-700 dark:text-slate-200">
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-emerald-500/15 border border-emerald-500/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span class="font-medium"><strong>15 días</strong> de prueba gratis</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span>Módulos de <strong>Pedidos, Clientes y Catálogo</strong></span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span>Tablero <strong>Kanban por flujos</strong></span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Recetas BOM</strong> y control de inventario</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span>Generador de <strong>Códigos de Barras</strong></span>
                            </li>
                        </ul>
                    </div>

                    <!-- Botón CTA Plan Estándar -->
                    <div class="mt-8 pt-4">
                        <Link
                            href="/register"
                            class="w-full py-3.5 rounded-xl font-bold text-sm text-center border-2 border-slate-300 dark:border-[#26334D] text-slate-800 dark:text-white bg-slate-50/80 dark:bg-[#1E293B]/60 hover:bg-slate-100 dark:hover:bg-[#1E293B] hover:border-slate-400 dark:hover:border-slate-500 shadow-sm transition-all duration-200 block cursor-pointer"
                        >
                            Empezar Prueba Gratis
                        </Link>
                    </div>
                </div>

                <!-- Opción B — Plan Taller Pro (El más recomendado / Destacado) -->
                <div
                    class="p-8 sm:p-9 rounded-3xl bg-gradient-to-b from-indigo-50/60 via-white to-white dark:from-[#172033] dark:via-[#151D2C] dark:to-[#151D2C] border-2 border-[#4F46E5] shadow-2xl shadow-[#4F46E5]/20 dark:shadow-[#4F46E5]/15 ring-4 ring-[#4F46E5]/10 flex flex-col justify-between relative hover:-translate-y-1 transition-all duration-300"
                >
                    <!-- Etiqueta Flotante Destacada -->
                    <span
                        class="absolute -top-4 right-6 sm:right-8 px-4 py-1.5 rounded-full text-xs font-extrabold text-white bg-gradient-to-r from-[#4F46E5] via-[#3B82F6] to-[#06B6D4] shadow-lg shadow-[#4F46E5]/30 flex items-center gap-1.5"
                    >
                        <span>🔥</span>
                        <span>¡Más Popular / Mayor Ahorro!</span>
                    </span>

                    <div>
                        <!-- Etiqueta del Plan -->
                        <span
                            class="text-xs font-bold uppercase tracking-wider text-[#4F46E5] dark:text-[#818CF8]"
                        >
                            Ideal para talleres en crecimiento con equipo operativo
                        </span>

                        <!-- Título del Plan -->
                        <h3
                            class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white mt-1.5"
                        >
                            Plan Taller Pro
                        </h3>

                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">
                            La potencia completa para talleres con múltiples trabajadores y alto volumen de despachos.
                        </p>

                        <!-- Precios del Plan -->
                        <div class="mt-6 pt-6 border-t border-indigo-100 dark:border-[#26334D]">
                            <div class="flex items-baseline gap-2">
                                <span
                                    class="text-4xl sm:text-5xl font-extrabold tracking-tight text-slate-900 dark:text-white"
                                >
                                    $75.000 COP
                                </span>
                                <span class="text-sm font-bold text-slate-500 dark:text-slate-400">
                                    / mes
                                </span>
                            </div>

                            <!-- Subtítulo del Precio -->
                            <div class="mt-2 flex items-center gap-2 text-xs font-semibold text-[#4F46E5] dark:text-[#06B6D4]">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-[#4F46E5]/10 dark:bg-[#4F46E5]/20 font-bold border border-[#4F46E5]/20">
                                    👥 Incluye hasta 5 Usuarios en total
                                </span>
                            </div>
                        </div>

                        <!-- Características Incluidas -->
                        <ul class="mt-7 space-y-3.5 text-sm text-slate-700 dark:text-slate-200">
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-emerald-500/15 border border-emerald-500/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span class="font-medium"><strong>15 días</strong> de prueba gratis</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#4F46E5]/15 border border-[#4F46E5]/40 text-[#4F46E5] dark:text-[#818CF8] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Hasta 5 usuarios simultáneos</strong> (Dueño + 4 operarios)</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Ahorro del 50%</strong> en usuarios adicionales</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Tableros Kanban ilimitados</strong> y múltiples flujos</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Kardex de Materia Prima</strong> y Proveedores (Lead Time)</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-5 h-5 rounded-full bg-[#06B6D4]/15 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center text-xs font-bold shrink-0">
                                    ✓
                                </span>
                                <span><strong>Soporte prioritario</strong> por WhatsApp</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Botón CTA Plan Taller Pro (Gradiente Sólido) -->
                    <div class="mt-8 pt-4">
                        <Link
                            href="/register?plan=pro"
                            class="w-full py-3.5 rounded-xl font-bold text-sm text-center text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#06B6D4] hover:from-[#4338CA] hover:to-[#0891B2] shadow-xl shadow-[#4F46E5]/30 hover:shadow-[#4F46E5]/45 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 block cursor-pointer"
                        >
                            Elegir Taller Pro →
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Micro-garantías de Tranquilidad Comercial -->
            <div
                class="mt-12 flex flex-wrap items-center justify-center gap-y-2 gap-x-8 text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400"
            >
                <span class="flex items-center gap-2">
                    <span class="text-emerald-500 font-bold">🔒</span> Sin tarjeta de crédito requerida
                </span>
                <span class="text-slate-300 dark:text-slate-700 hidden sm:inline">•</span>
                <span class="flex items-center gap-2">
                    <span class="text-[#06B6D4] font-bold">⚡</span> Activación inmediata en 2 minutos
                </span>
                <span class="text-slate-300 dark:text-slate-700 hidden sm:inline">•</span>
                <span class="flex items-center gap-2">
                    <span class="text-[#4F46E5] font-bold">🛡️</span> Cancela o cambia de plan cuando quieras
                </span>
            </div>

            <!-- ================================================================= -->
            <!-- 3. SECCIÓN DE PREGUNTAS FRECUENTES (FAQ) RÁPIDA                   -->
            <!-- ================================================================= -->
            <div class="mt-20 sm:mt-28 max-w-3xl mx-auto text-left">
                <div class="text-center mb-10">
                    <div
                        class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-slate-100 dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-3"
                    >
                        <span>❓ RESOLVEMOS TUS DUDAS</span>
                    </div>
                    <h3
                        class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white"
                    >
                        Preguntas Frecuentes de Talleres y Pymes
                    </h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Respuestas claras para que arranques tu prueba con total confianza.
                    </p>
                </div>

                <div class="space-y-3.5">
                    {#each faqs as faq, idx}
                        <div
                            class="rounded-2xl border transition-all duration-200 overflow-hidden {openFaqIndex === idx
                                ? 'border-[#4F46E5] bg-white dark:bg-[#151D2C] shadow-md shadow-[#4F46E5]/10'
                                : 'border-slate-200/90 dark:border-[#26334D] bg-white/70 dark:bg-[#151D2C]/60 hover:border-slate-300 dark:hover:border-slate-600'}"
                        >
                            <button
                                type="button"
                                onclick={() => toggleFaq(idx)}
                                class="w-full px-5 sm:px-6 py-4.5 text-left font-bold text-sm sm:text-base text-slate-900 dark:text-white flex items-center justify-between gap-4 cursor-pointer focus:outline-none"
                                aria-expanded={openFaqIndex === idx}
                            >
                                <span class="flex items-center gap-3">
                                    <span
                                        class="text-[#4F46E5] dark:text-[#06B6D4] text-xs font-mono font-bold"
                                    >
                                        0{idx + 1}.
                                    </span>
                                    <span>{faq.question}</span>
                                </span>
                                <span
                                    class="w-6 h-6 rounded-full bg-slate-100 dark:bg-[#0B0F17] flex items-center justify-center text-xs text-slate-500 dark:text-slate-400 shrink-0 transition-transform duration-200 {openFaqIndex === idx
                                        ? 'rotate-180 bg-[#4F46E5]/10 text-[#4F46E5] dark:text-[#06B6D4]'
                                        : ''}"
                                >
                                    ▼
                                </span>
                            </button>

                            {#if openFaqIndex === idx}
                                <div
                                    class="px-5 sm:px-6 pb-5 pt-1 text-xs sm:text-sm text-slate-600 dark:text-slate-300 leading-relaxed border-t border-slate-100 dark:border-[#26334D]/70"
                                >
                                    {faq.answer}
                                </div>
                            {/if}
                        </div>
                    {/each}
                </div>

                <!-- Tarjeta de Asesoría Directa -->
                <div
                    class="mt-10 p-5 rounded-2xl bg-gradient-to-r from-slate-100 via-white to-slate-100 dark:from-[#151D2C] dark:via-[#192335] dark:to-[#151D2C] border border-slate-200 dark:border-[#26334D] flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left"
                >
                    <div class="flex items-center gap-3.5">
                        <span class="text-2xl sm:text-3xl shrink-0">💬</span>
                        <div>
                            <div class="text-sm font-bold text-slate-900 dark:text-white">
                                ¿Tienes dudas sobre la capacidad de tu taller?
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Te ayudamos a configurar tus primeros flujos y estimar la carga de trabajo.
                            </div>
                        </div>
                    </div>
                    <Link
                        href="/register"
                        class="px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-[#4F46E5] hover:bg-[#4338CA] transition-all shrink-0 shadow-md shadow-[#4F46E5]/20 cursor-pointer"
                    >
                        Comenzar Ahora →
                    </Link>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================================================================= -->
    <!-- 4. PIE DE PÁGINA COMERCIAL (FOOTER DE LA LANDING)                         -->
    <!-- ========================================================================= -->
    <footer
        class="border-t border-slate-200/80 dark:border-[#26334D] bg-white dark:bg-[#0B0F17] text-slate-600 dark:text-slate-400 text-xs transition-colors duration-300"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-16">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 lg:gap-8">
                <!-- Columna 1 y 2: Identidad gipPyme + Mensaje Comercial Principal -->
                <div class="lg:col-span-2 space-y-4 text-left">
                    <a
                        href="/"
                        class="inline-block focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4F46E5] rounded-xl"
                        aria-label="gipPyme Inicio"
                    >
                        <BrandLogo
                            variant="horizontal"
                            size="md"
                            showSubtitle={true}
                        />
                    </a>
                    
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 max-w-sm leading-relaxed">
                        Gestor Inteligente de Pedidos — Hecho con pasión para las pymes de Latinoamérica.
                    </p>

                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm leading-relaxed">
                        Predicción de fechas de entrega, tableros Kanban dinámicos, recetas BOM y kardex de materia prima en una sola plataforma en la nube.
                    </p>
                </div>

                <!-- Columna 3: Navegación de Producto -->
                <div class="space-y-3 text-left">
                    <h5 class="text-xs font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                        Producto
                    </h5>
                    <ul class="space-y-2 text-xs">
                        <li>
                            <button
                                type="button"
                                onclick={() => scrollToSection("producto")}
                                class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors cursor-pointer"
                            >
                                Visión General
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                onclick={() => scrollToSection("escaparate-demo")}
                                class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors cursor-pointer"
                            >
                                Capturas del Sistema
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                onclick={() => scrollToSection("modulos")}
                                class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors cursor-pointer"
                            >
                                Los 5 Módulos
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                onclick={() => scrollToSection("kanban")}
                                class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors cursor-pointer"
                            >
                                Tableros Kanban
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                onclick={() => scrollToSection("precios")}
                                class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors cursor-pointer font-semibold text-[#4F46E5] dark:text-[#818CF8]"
                            >
                                Planes y Precios
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Columna 4: Accesos Rápidos de Cuenta -->
                <div class="space-y-3 text-left">
                    <h5 class="text-xs font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                        Tu Cuenta
                    </h5>
                    <ul class="space-y-2 text-xs">
                        {#if authUser}
                            <li>
                                <Link
                                    href="/dashboard"
                                    class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors font-bold text-[#4F46E5] dark:text-[#06B6D4]"
                                >
                                    Ir a mi Taller →
                                </Link>
                            </li>
                        {:else}
                            <li>
                                <Link
                                    href="/login"
                                    class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors"
                                >
                                    Iniciar Sesión
                                </Link>
                            </li>
                            <li>
                                <Link
                                    href="/register"
                                    class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors font-semibold text-[#4F46E5] dark:text-[#818CF8]"
                                >
                                    Crear Taller Gratis
                                </Link>
                            </li>
                            <li>
                                <Link
                                    href="/register?plan=pro"
                                    class="hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors"
                                >
                                    Elegir Plan Taller Pro
                                </Link>
                            </li>
                        {/if}
                    </ul>
                </div>

                <!-- Columna 5: Enlaces Legales y Garantías -->
                <div class="space-y-3 text-left">
                    <h5 class="text-xs font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                        Legal y Seguridad
                    </h5>
                    <ul class="space-y-2 text-xs">
                        <li>
                            <span class="hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition-colors cursor-pointer">
                                Términos de Servicio
                            </span>
                        </li>
                        <li>
                            <span class="hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition-colors cursor-pointer">
                                Política de Privacidad
                            </span>
                        </li>
                        <li>
                            <span class="hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition-colors cursor-pointer">
                                Aislamiento Multi-Tenant
                            </span>
                        </li>
                        <li>
                            <span class="hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition-colors cursor-pointer">
                                Pagos Seguros en Colombia
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Línea inferior de copyright y país -->
            <div
                class="mt-12 pt-8 border-t border-slate-200/70 dark:border-[#26334D] flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 dark:text-slate-400"
            >
                <div class="flex items-center gap-2">
                    <span>© {new Date().getFullYear()} gipPyme. Todos los derechos reservados.</span>
                </div>
                <div class="flex items-center gap-3 text-[11px]">
                    <span class="flex items-center gap-1.5 font-medium">
                        <span>🇨🇴</span>
                        <span>Colombia</span>
                    </span>
                    <span>•</span>
                    <span class="text-slate-400 dark:text-slate-500">Hecho para talleres y pymes</span>
                </div>
            </div>
        </div>
    </footer>
</div>

<style>
    /* Animaciones suaves para las tarjetas flotantes en desktop */
    @keyframes badgeFloat1 {
        0%,
        100% {
            transform: translateY(0px);
        }
        50% {
            transform: translateY(-8px);
        }
    }

    @keyframes badgeFloat2 {
        0%,
        100% {
            transform: translateY(0px);
        }
        50% {
            transform: translateY(8px);
        }
    }

    .floating-badge-top {
        animation: badgeFloat1 4.5s ease-in-out infinite;
    }

    .floating-badge-bottom {
        animation: badgeFloat2 5.5s ease-in-out infinite;
    }

    /* Animación de entrada suave para el modal Lightbox */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: scale(0.98);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .animate-fade-in {
        animation: fadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
</style>
