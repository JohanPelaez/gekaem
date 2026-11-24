/**
 * Svelte Action para contenedores horizontales de tableros Kanban.
 *
 * Capacidades:
 * 1. Auto-scroll horizontal y vertical (dentro de [data-kanban-column-body]) con requestAnimationFrame durante Drag & Drop.
 * 2. Notificación reactiva del estado de scroll ({ canScrollLeft, canScrollRight, isDraggingNearEdge }).
 * 3. Navegación rápida horizontal con Shift + Rueda del mouse.
 * 4. Grab-to-pan al arrastrar sobre el fondo vacío del tablero.
 *
 * @param {HTMLElement} node
 * @param {{
 *   onScrollStateChange?: (state: { canScrollLeft: boolean, canScrollRight: boolean, isDraggingNearEdge: 'left' | 'right' | null }) => void,
 *   edgeZoneX?: number,
 *   edgeZoneY?: number,
 *   minSpeedX?: number,
 *   maxSpeedX?: number,
 *   minSpeedY?: number,
 *   maxSpeedY?: number,
 * }} [options]
 */
export function kanbanBoardScroll(node, options = {}) {
    let config = {
        onScrollStateChange: options?.onScrollStateChange ?? null,
        edgeZoneX: options?.edgeZoneX ?? 110,
        edgeZoneY: options?.edgeZoneY ?? 65,
        minSpeedX: options?.minSpeedX ?? 6,
        maxSpeedX: options?.maxSpeedX ?? 24,
        minSpeedY: options?.minSpeedY ?? 4,
        maxSpeedY: options?.maxSpeedY ?? 16,
    };

    // Estado de Drag & Drop + Auto-scroll
    let isDragging = false;
    let pointerX = 0;
    let pointerY = 0;
    let hasPointerPosition = false;
    let activeColumnBody = null;
    let rafId = null;
    let draggingNearEdge = null; // 'left' | 'right' | null

    // Estado de Grab-to-pan
    let isPanning = false;
    let panStartX = 0;
    let panStartScrollLeft = 0;
    let previousUserSelect = '';
    let previousCursor = '';

    // Último estado emitido para evitar actualizaciones redundantes
    let lastEmitted = {
        canScrollLeft: null,
        canScrollRight: null,
        isDraggingNearEdge: undefined,
    };

    function computeCanScroll() {
        const canScrollLeft = node.scrollLeft > 2;
        const canScrollRight = node.scrollLeft + node.clientWidth < node.scrollWidth - 2;
        return { canScrollLeft, canScrollRight };
    }

    function emitScrollState(force = false) {
        if (typeof config.onScrollStateChange !== 'function') return;

        const { canScrollLeft, canScrollRight } = computeCanScroll();
        const isDraggingNearEdge = isDragging ? draggingNearEdge : null;

        if (
            !force &&
            lastEmitted.canScrollLeft === canScrollLeft &&
            lastEmitted.canScrollRight === canScrollRight &&
            lastEmitted.isDraggingNearEdge === isDraggingNearEdge
        ) {
            return;
        }

        lastEmitted = { canScrollLeft, canScrollRight, isDraggingNearEdge };
        config.onScrollStateChange({
            canScrollLeft,
            canScrollRight,
            isDraggingNearEdge,
        });
    }

    function updateActiveColumnBody(target) {
        if (target && typeof target.closest === 'function') {
            const colBody = target.closest('[data-kanban-column-body]');
            if (colBody && node.contains(colBody)) {
                activeColumnBody = colBody;
                return;
            }
        }
        activeColumnBody = null;
    }

    function stopAutoScroll() {
        isDragging = false;
        hasPointerPosition = false;
        activeColumnBody = null;
        draggingNearEdge = null;
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        emitScrollState();
    }

    function autoScrollTick() {
        if (!isDragging) {
            rafId = null;
            return;
        }

        let nextNearEdge = null;

        if (hasPointerPosition) {
            const rect = node.getBoundingClientRect();
            const edgeX = config.edgeZoneX;
            const withinVerticalBand = pointerY >= rect.top - 80 && pointerY <= rect.bottom + 80;

            if (withinVerticalBand) {
                // Auto-scroll horizontal hacia la izquierda (incluye si el cursor entra ligeramente al sidebar)
                if (pointerX <= rect.left + edgeX && node.scrollLeft > 0) {
                    const distanceIntoZone = Math.min(edgeX, Math.max(0, rect.left + edgeX - pointerX));
                    const ratio = distanceIntoZone / edgeX;
                    const speedX = config.minSpeedX + ratio * (config.maxSpeedX - config.minSpeedX);
                    node.scrollLeft = Math.max(0, node.scrollLeft - speedX);
                    nextNearEdge = 'left';
                }
                // Auto-scroll horizontal hacia la derecha
                else if (
                    pointerX >= rect.right - edgeX &&
                    node.scrollLeft + node.clientWidth < node.scrollWidth - 1
                ) {
                    const distanceIntoZone = Math.min(edgeX, Math.max(0, pointerX - (rect.right - edgeX)));
                    const ratio = distanceIntoZone / edgeX;
                    const speedX = config.minSpeedX + ratio * (config.maxSpeedX - config.minSpeedX);
                    const maxScrollLeft = Math.max(0, node.scrollWidth - node.clientWidth);
                    node.scrollLeft = Math.min(maxScrollLeft, node.scrollLeft + speedX);
                    nextNearEdge = 'right';
                }
            }

            // Auto-scroll vertical dentro de columnas largas
            if (activeColumnBody && activeColumnBody.scrollHeight > activeColumnBody.clientHeight + 1) {
                const colRect = activeColumnBody.getBoundingClientRect();
                const edgeY = config.edgeZoneY;
                const withinColX = pointerX >= colRect.left - 20 && pointerX <= colRect.right + 20;

                if (withinColX) {
                    if (pointerY < colRect.top + edgeY && activeColumnBody.scrollTop > 0) {
                        const distY = Math.min(edgeY, Math.max(0, colRect.top + edgeY - pointerY));
                        const ratioY = distY / edgeY;
                        const speedY = config.minSpeedY + ratioY * (config.maxSpeedY - config.minSpeedY);
                        activeColumnBody.scrollTop = Math.max(0, activeColumnBody.scrollTop - speedY);
                    } else if (
                        pointerY > colRect.bottom - edgeY &&
                        activeColumnBody.scrollTop + activeColumnBody.clientHeight < activeColumnBody.scrollHeight - 1
                    ) {
                        const distY = Math.min(edgeY, Math.max(0, pointerY - (colRect.bottom - edgeY)));
                        const ratioY = distY / edgeY;
                        const speedY = config.minSpeedY + ratioY * (config.maxSpeedY - config.minSpeedY);
                        const maxScrollTop = Math.max(0, activeColumnBody.scrollHeight - activeColumnBody.clientHeight);
                        activeColumnBody.scrollTop = Math.min(maxScrollTop, activeColumnBody.scrollTop + speedY);
                    }
                }
            }
        }

        if (draggingNearEdge !== nextNearEdge) {
            draggingNearEdge = nextNearEdge;
        }
        emitScrollState();

        rafId = requestAnimationFrame(autoScrollTick);
    }

    function startAutoScroll() {
        if (!isDragging) {
            isDragging = true;
        }
        if (rafId === null) {
            rafId = requestAnimationFrame(autoScrollTick);
        }
        emitScrollState();
    }

    // --- Listeners de Drag & Drop ---
    function handleWindowDragStart(e) {
        if (!node.contains(e.target)) return;
        if (typeof e.clientX === 'number' && typeof e.clientY === 'number') {
            pointerX = e.clientX;
            pointerY = e.clientY;
            hasPointerPosition = true;
        }
        startAutoScroll();
    }

    function handleNodeDragOver(e) {
        if (!isDragging) {
            startAutoScroll();
        }
        // Evita que el navegador corte el evento dragover en huecos entre columnas o en los bordes
        e.preventDefault();
        pointerX = e.clientX;
        pointerY = e.clientY;
        hasPointerPosition = true;
        updateActiveColumnBody(e.target);
    }

    function handleWindowDragOver(e) {
        if (!isDragging) return;
        pointerX = e.clientX;
        pointerY = e.clientY;
        hasPointerPosition = true;

        const rect = node.getBoundingClientRect();
        const withinVerticalBand = pointerY >= rect.top - 80 && pointerY <= rect.bottom + 80;

        if (withinVerticalBand) {
            e.preventDefault();
        }

        updateActiveColumnBody(e.target);
    }

    function handleStopDrag() {
        if (isDragging || rafId !== null) {
            stopAutoScroll();
        }
    }

    // --- Listener de Shift + Rueda del mouse ---
    function handleWheel(e) {
        if (!e.shiftKey || e.deltaY === 0) return;

        const maxScrollLeft = node.scrollWidth - node.clientWidth;
        if (maxScrollLeft <= 0) return;

        e.preventDefault();
        node.scrollLeft += e.deltaY;
        emitScrollState();
    }

    // --- Listeners de Grab-to-pan ---
    const INTERACTIVE_SELECTOR =
        '[draggable="true"], button, input, select, textarea, a, label, [role="button"], [contenteditable="true"]';

    function endPan() {
        if (!isPanning) return;
        isPanning = false;
        node.style.cursor = previousCursor;
        document.body.style.userSelect = previousUserSelect;
        window.removeEventListener('mousemove', handlePanMove);
        window.removeEventListener('mouseup', endPan);
        window.removeEventListener('blur', endPan);
    }

    function handlePanMove(e) {
        if (!isPanning) return;
        const deltaX = e.clientX - panStartX;
        node.scrollLeft = panStartScrollLeft - deltaX;
        emitScrollState();
    }

    function handleMouseDown(e) {
        if (e.button !== 0) return;
        if (node.scrollWidth <= node.clientWidth + 2) return;
        if (e.target && typeof e.target.closest === 'function' && e.target.closest(INTERACTIVE_SELECTOR)) {
            return;
        }

        isPanning = true;
        panStartX = e.clientX;
        panStartScrollLeft = node.scrollLeft;
        previousCursor = node.style.cursor;
        previousUserSelect = document.body.style.userSelect;
        node.style.cursor = 'grabbing';
        document.body.style.userSelect = 'none';

        window.addEventListener('mousemove', handlePanMove);
        window.addEventListener('mouseup', endPan);
        window.addEventListener('blur', endPan);
    }

    // --- Listener de scroll y observers de tamaño/contenido ---
    function handleScroll() {
        emitScrollState();
    }

    const resizeObserver =
        typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(() => {
                  emitScrollState();
              })
            : null;

    const mutationObserver =
        typeof MutationObserver !== 'undefined'
            ? new MutationObserver(() => {
                  emitScrollState();
              })
            : null;

    if (resizeObserver) {
        resizeObserver.observe(node);
    }
    if (mutationObserver) {
        mutationObserver.observe(node, { childList: true, subtree: false });
    }

    node.addEventListener('scroll', handleScroll, { passive: true });
    node.addEventListener('dragover', handleNodeDragOver);
    node.addEventListener('drop', handleStopDrag);
    node.addEventListener('wheel', handleWheel, { passive: false });
    node.addEventListener('mousedown', handleMouseDown);

    window.addEventListener('dragstart', handleWindowDragStart, true);
    window.addEventListener('dragover', handleWindowDragOver);
    window.addEventListener('dragend', handleStopDrag);
    window.addEventListener('drop', handleStopDrag);
    window.addEventListener('blur', handleStopDrag);

    // Emisión inicial tras el primer layout paint
    const initRaf = requestAnimationFrame(() => {
        emitScrollState(true);
    });

    return {
        update(newOptions = {}) {
            config = {
                onScrollStateChange: newOptions?.onScrollStateChange ?? null,
                edgeZoneX: newOptions?.edgeZoneX ?? 110,
                edgeZoneY: newOptions?.edgeZoneY ?? 65,
                minSpeedX: newOptions?.minSpeedX ?? 6,
                maxSpeedX: newOptions?.maxSpeedX ?? 24,
                minSpeedY: newOptions?.minSpeedY ?? 4,
                maxSpeedY: newOptions?.maxSpeedY ?? 16,
            };
            emitScrollState(true);
        },
        destroy() {
            cancelAnimationFrame(initRaf);
            stopAutoScroll();
            endPan();

            if (resizeObserver) resizeObserver.disconnect();
            if (mutationObserver) mutationObserver.disconnect();

            node.removeEventListener('scroll', handleScroll);
            node.removeEventListener('dragover', handleNodeDragOver);
            node.removeEventListener('drop', handleStopDrag);
            node.removeEventListener('wheel', handleWheel);
            node.removeEventListener('mousedown', handleMouseDown);

            window.removeEventListener('dragstart', handleWindowDragStart, true);
            window.removeEventListener('dragover', handleWindowDragOver);
            window.removeEventListener('dragend', handleStopDrag);
            window.removeEventListener('drop', handleStopDrag);
            window.removeEventListener('blur', handleStopDrag);
        },
    };
}

/**
 * Svelte Action que ajusta dinámicamente la altura exacta del wrapper del tablero Kanban
 * al espacio disponible del viewport en escritorio (>= 768px), evitando desbordamiento vertical
 * y manteniendo la barra de scroll horizontal siempre visible desde el primer instante.
 *
 * @param {HTMLElement} node
 * @param {{ bottomGap?: number, minHeight?: number }} [options]
 */
export function fitKanbanViewport(node, options = {}) {
    let config = {
        bottomGap: options?.bottomGap ?? 20,
        minHeight: options?.minHeight ?? 360,
    };

    let rafId = null;

    const updateHeight = () => {
        if (typeof window === 'undefined') return;

        if (window.innerWidth < 768) {
            if (node.style.height !== '') {
                node.style.height = '';
            }
            return;
        }

        const rect = node.getBoundingClientRect();
        const available = Math.max(
            config.minHeight,
            Math.floor(window.innerHeight - rect.top - config.bottomGap)
        );
        const nextHeight = `${available}px`;

        if (node.style.height !== nextHeight) {
            node.style.height = nextHeight;
        }
    };

    const scheduleUpdate = () => {
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
        }
        rafId = requestAnimationFrame(() => {
            rafId = null;
            updateHeight();
        });
    };

    // Ejecución inmediata + reajuste en el primer frame (fuentes/filtros)
    updateHeight();
    scheduleUpdate();

    window.addEventListener('resize', updateHeight, { passive: true });

    const mainEl = typeof node.closest === 'function' ? node.closest('main') : null;

    const resizeObserver =
        typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(() => {
                  scheduleUpdate();
              })
            : null;

    if (resizeObserver) {
        if (node.parentElement) {
            resizeObserver.observe(node.parentElement);
        }
        if (node.previousElementSibling) {
            resizeObserver.observe(node.previousElementSibling);
        }
        if (mainEl) {
            resizeObserver.observe(mainEl);
        }
    }

    const mutationObserver =
        typeof MutationObserver !== 'undefined' && mainEl
            ? new MutationObserver(() => {
                  scheduleUpdate();
              })
            : null;

    if (mutationObserver && mainEl) {
        mutationObserver.observe(mainEl, { childList: true });
    }

    return {
        update(newOptions = {}) {
            config = {
                bottomGap: newOptions?.bottomGap ?? 20,
                minHeight: newOptions?.minHeight ?? 360,
            };
            updateHeight();
        },
        destroy() {
            if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
            window.removeEventListener('resize', updateHeight);
            if (resizeObserver) {
                resizeObserver.disconnect();
            }
            if (mutationObserver) {
                mutationObserver.disconnect();
            }
            node.style.height = '';
        },
    };
}
