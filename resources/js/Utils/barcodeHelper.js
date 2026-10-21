import JsBarcode from 'jsbarcode';

/**
 * Catálogo de los 6 formatos de códigos de barras soportados en Gekaem
 */
export const BARCODE_FORMATS = [
    { value: 'CODE128', label: 'Code 128 (Alfanumérico estándar)', hint: 'Acepta letras, números y guiones' },
    { value: 'CODE39', label: 'Code 39 (Industrial / Alfanumérico)', hint: 'Acepta MAYÚSCULAS, números y - . $ / + %' },
    { value: 'EAN13', label: 'EAN-13 (Comercial 13 dígitos)', hint: '12 o 13 dígitos numéricos' },
    { value: 'UPC', label: 'UPC-A (Comercial 12 dígitos)', hint: '11 o 12 dígitos numéricos' },
    { value: 'EAN8', label: 'EAN-8 (Compacto 8 dígitos)', hint: '7 u 8 dígitos numéricos' },
    { value: 'ITF14', label: 'ITF-14 / GTIN-14 (Cajas y Embalaje 14 dígitos)', hint: '13 o 14 dígitos numéricos' },
];

/**
 * Algoritmo matemático estándar Módulo 10 (GS1) para calcular el dígito de control.
 * Compatible con payloads numéricos de 7, 11, 12 y 13 dígitos (o cualquier longitud GS1).
 *
 * @param {string|number} digits
 * @returns {number}
 */
export function calculateGs1CheckDigit(digits) {
    const str = String(digits).replace(/\D/g, '');
    let sum = 0;
    for (let i = 0; i < str.length; i++) {
        // Ponderación desde la derecha hacia la izquierda: posición 0 (último dígito) pesa 3, siguiente 1...
        const idxFromRight = str.length - 1 - i;
        const weight = (idxFromRight % 2 === 0) ? 3 : 1;
        sum += parseInt(str[i], 10) * weight;
    }
    return (10 - (sum % 10)) % 10;
}

/**
 * Genera un código de barras consecutivo y matemáticamente válido según el formato seleccionado.
 *
 * @param {string} format 'CODE128' | 'CODE39' | 'EAN13' | 'UPC' | 'EAN8' | 'ITF14'
 * @param {number} sequenceNumber
 * @param {number} tenantId
 * @returns {string}
 */
export function generateSequentialBarcode(format, sequenceNumber = 1, tenantId = 1) {
    const seq = Math.max(1, parseInt(sequenceNumber, 10) || 1);
    const tId = Math.max(1, parseInt(tenantId, 10) || 1);

    switch (format) {
        case 'CODE128':
        case 'CODE39': {
            const tenantStr = String(tId).padStart(2, '0').slice(-2);
            const seqStr = String(seq).padStart(4, '0');
            return `PRD-${tenantStr}-${seqStr}`;
        }

        case 'EAN13': {
            // Prefijo 770 (Colombia) + 2 dígitos tenant + 7 dígitos secuencia = 12 dígitos base
            const tenantStr = String(tId).padStart(2, '0').slice(-2);
            const seqStr = String(seq).padStart(7, '0').slice(-7);
            const base12 = `770${tenantStr}${seqStr}`;
            const check = calculateGs1CheckDigit(base12);
            return `${base12}${check}`;
        }

        case 'UPC': {
            // Prefijo 0 + 2 dígitos tenant + 8 dígitos secuencia = 11 dígitos base
            const tenantStr = String(tId).padStart(2, '0').slice(-2);
            const seqStr = String(seq).padStart(8, '0').slice(-8);
            const base11 = `0${tenantStr}${seqStr}`;
            const check = calculateGs1CheckDigit(base11);
            return `${base11}${check}`;
        }

        case 'EAN8': {
            // Prefijo 20 (Uso interno) + 5 dígitos secuencia = 7 dígitos base
            const seqStr = String(seq).padStart(5, '0').slice(-5);
            const base7 = `20${seqStr}`;
            const check = calculateGs1CheckDigit(base7);
            return `${base7}${check}`;
        }

        case 'ITF14': {
            // Indicador de nivel de empaque 1 + Prefijo 770 + 2 dígitos tenant + 7 dígitos secuencia = 13 dígitos base
            const tenantStr = String(tId).padStart(2, '0').slice(-2);
            const seqStr = String(seq).padStart(7, '0').slice(-7);
            const base13 = `1770${tenantStr}${seqStr}`;
            const check = calculateGs1CheckDigit(base13);
            return `${base13}${check}`;
        }

        default: {
            const tenantStr = String(tId).padStart(2, '0').slice(-2);
            const seqStr = String(seq).padStart(4, '0');
            return `PRD-${tenantStr}-${seqStr}`;
        }
    }
}

/**
 * Valida si un código de barras cumple las reglas sintácticas del formato antes de invocar JsBarcode.
 *
 * @param {string} code
 * @param {string} format
 * @returns {{ valid: boolean, message: string }}
 */
export function validateBarcodeForFormat(code, format = 'CODE128') {
    if (!code || typeof code !== 'string' || !code.trim()) {
        return { valid: false, message: 'Ingresa un código de barras.' };
    }

    const clean = code.trim();

    switch (format) {
        case 'CODE128': {
            if (/^[\x20-\x7E]+$/.test(clean)) {
                return { valid: true, message: 'Válido para Code 128' };
            }
            return { valid: false, message: 'Code 128 admite letras, números y símbolos ASCII estándar.' };
        }

        case 'CODE39': {
            const upper = clean.toUpperCase();
            if (/^[0-9A-Z\-\.\ \$\/\+\%]+$/.test(upper)) {
                return { valid: true, message: 'Válido para Code 39' };
            }
            return { valid: false, message: 'Code 39 solo admite MAYÚSCULAS, números y - . $ / + % espacio.' };
        }

        case 'EAN13': {
            if (!/^\d+$/.test(clean)) {
                return { valid: false, message: 'EAN-13 requiere dígitos numéricos únicamente.' };
            }
            if (clean.length === 12) {
                return { valid: true, message: '12 dígitos ingresados (se calculará el dígito de control).' };
            }
            if (clean.length === 13) {
                const base = clean.slice(0, 12);
                const expectedCheck = calculateGs1CheckDigit(base);
                const actualCheck = parseInt(clean[12], 10);
                if (expectedCheck === actualCheck) {
                    return { valid: true, message: 'EAN-13 verificado y válido.' };
                }
                return { valid: false, message: `Dígito de control inválido. Para "${base}" debe terminar en ${expectedCheck}.` };
            }
            return { valid: false, message: `EAN-13 requiere exactamente 12 o 13 dígitos (ingresados: ${clean.length}).` };
        }

        case 'UPC': {
            if (!/^\d+$/.test(clean)) {
                return { valid: false, message: 'UPC-A requiere dígitos numéricos únicamente.' };
            }
            if (clean.length === 11) {
                return { valid: true, message: '11 dígitos ingresados (se calculará el dígito de control).' };
            }
            if (clean.length === 12) {
                const base = clean.slice(0, 11);
                const expectedCheck = calculateGs1CheckDigit(base);
                const actualCheck = parseInt(clean[11], 10);
                if (expectedCheck === actualCheck) {
                    return { valid: true, message: 'UPC-A verificado y válido.' };
                }
                return { valid: false, message: `Dígito de control inválido. Para "${base}" debe terminar en ${expectedCheck}.` };
            }
            return { valid: false, message: `UPC-A requiere exactamente 11 o 12 dígitos (ingresados: ${clean.length}).` };
        }

        case 'EAN8': {
            if (!/^\d+$/.test(clean)) {
                return { valid: false, message: 'EAN-8 requiere dígitos numéricos únicamente.' };
            }
            if (clean.length === 7) {
                return { valid: true, message: '7 dígitos ingresados (se calculará el dígito de control).' };
            }
            if (clean.length === 8) {
                const base = clean.slice(0, 7);
                const expectedCheck = calculateGs1CheckDigit(base);
                const actualCheck = parseInt(clean[7], 10);
                if (expectedCheck === actualCheck) {
                    return { valid: true, message: 'EAN-8 verificado y válido.' };
                }
                return { valid: false, message: `Dígito de control inválido. Para "${base}" debe terminar en ${expectedCheck}.` };
            }
            return { valid: false, message: `EAN-8 requiere exactamente 7 u 8 dígitos (ingresados: ${clean.length}).` };
        }

        case 'ITF14': {
            if (!/^\d+$/.test(clean)) {
                return { valid: false, message: 'ITF-14 requiere dígitos numéricos únicamente.' };
            }
            if (clean.length === 13) {
                return { valid: true, message: '13 dígitos ingresados (se calculará el dígito de control).' };
            }
            if (clean.length === 14) {
                const base = clean.slice(0, 13);
                const expectedCheck = calculateGs1CheckDigit(base);
                const actualCheck = parseInt(clean[13], 10);
                if (expectedCheck === actualCheck) {
                    return { valid: true, message: 'ITF-14 verificado y válido.' };
                }
                return { valid: false, message: `Dígito de control inválido. Para "${base}" debe terminar en ${expectedCheck}.` };
            }
            return { valid: false, message: `ITF-14 requiere exactamente 13 o 14 dígitos (ingresados: ${clean.length}).` };
        }

        default:
            return { valid: true, message: 'Formato verificado' };
    }
}

/**
 * Genera y descarga el código de barras en formato PNG de alta resolución con título de producto opcional.
 *
 * @param {string} code
 * @param {string} format
 * @param {string} productName
 */
export function downloadBarcodeAsPng(code, format = 'CODE128', productName = '') {
    if (!code || typeof window === 'undefined') return;

    const validation = validateBarcodeForFormat(code, format);
    if (!validation.valid) {
        alert(validation.message || 'El código de barras no es compatible con el formato seleccionado.');
        return;
    }

    try {
        const barcodeCanvas = document.createElement('canvas');
        JsBarcode(barcodeCanvas, code.trim(), {
            format: format || 'CODE128',
            width: 2,
            height: 80,
            displayValue: true,
            font: 'monospace',
            fontOptions: 'bold',
            fontSize: 16,
            textMargin: 6,
            background: '#ffffff',
            lineColor: '#000000',
            margin: 16,
        });

        let targetCanvas = barcodeCanvas;

        if (productName && productName.trim()) {
            const cleanTitle = productName.trim();
            const headerHeight = 44;
            const finalCanvas = document.createElement('canvas');
            const targetWidth = Math.max(barcodeCanvas.width, 320);

            finalCanvas.width = targetWidth;
            finalCanvas.height = barcodeCanvas.height + headerHeight;

            const ctx = finalCanvas.getContext('2d');
            if (ctx) {
                // Fondo blanco
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, finalCanvas.width, finalCanvas.height);

                // Nombre del producto centrado
                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 15px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                // Truncar con elipsis si excede el ancho disponible
                let displayTitle = cleanTitle;
                const maxTextWidth = targetWidth - 32;
                if (ctx.measureText(displayTitle).width > maxTextWidth) {
                    while (displayTitle.length > 3 && ctx.measureText(displayTitle + '...').width > maxTextWidth) {
                        displayTitle = displayTitle.slice(0, -1);
                    }
                    displayTitle += '...';
                }

                ctx.fillText(displayTitle, targetWidth / 2, 24);

                // Línea divisoria suave
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(16, 42);
                ctx.lineTo(targetWidth - 16, 42);
                ctx.stroke();

                // Dibujar código de barras centrado
                const xOffset = (targetWidth - barcodeCanvas.width) / 2;
                ctx.drawImage(barcodeCanvas, xOffset, headerHeight);

                targetCanvas = finalCanvas;
            }
        }

        const dataUrl = targetCanvas.toDataURL('image/png');
        const link = document.createElement('a');
        const sanitizedCode = code.replace(/[^a-zA-Z0-9_-]/g, '_');
        link.download = `barcode-${sanitizedCode}.png`;
        link.href = dataUrl;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (err) {
        console.error('Error al generar la imagen PNG del código de barras:', err);
        alert('No se pudo generar la imagen del código de barras. Verifica que el código sea compatible con el formato seleccionado.');
    }
}
