/**
 * Helpers for printing (Thermal 10x15cm or Letter/A4) and exporting high-res PNG
 * of the Shipping Guide ("Guía de Envío") without heavy external dependencies.
 */

export function formatDateDDMMYYYY(isoDate) {
    if (!isoDate) return '';
    const match = String(isoDate).trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return String(isoDate);
    return `${match[3]}/${match[2]}/${match[1]}`;
}

export function slugifyFilename(text) {
    return String(text || 'cliente')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40) || 'cliente';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function buildCityDept(city, department) {
    return [city, department].map((v) => String(v || '').trim()).filter(Boolean).join(', ');
}

/**
 * Print the shipping guide using a clean hidden iframe with exact B&W print styles.
 */
export function printShippingGuide({
    guideNumber,
    formattedDate,
    paymentMode,
    sender,
    recipient,
    observations,
    printFormat = 'thermal',
}) {
    const headerTitle = (sender?.shipping_header || sender?.name || 'LOGÍSTICA & ENVÍOS EXPRESS').trim().toUpperCase();
    const senderCityDept = buildCityDept(sender?.city, sender?.department);
    const isOriginPaid = paymentMode === 'origen';
    const isContraEntrega = !isOriginPaid;
    const isThermal = printFormat === 'thermal';

    const pageCss = isThermal
        ? `@page { size: 100mm 150mm; margin: 4mm; }
           body { margin: 0; padding: 0; background: #FFFFFF; }
           .sheet { width: 100%; }`
        : `@page { size: letter portrait; margin: 12mm; }
           body { margin: 0; padding: 8mm 0; background: #FFFFFF; display: flex; justify-content: center; align-items: flex-start; }
           .sheet { width: 105mm; min-height: 150mm; border: 1px dashed #666666; padding: 4mm; box-sizing: border-box; }`;

    const html = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Guía de Envío ${escapeHtml(guideNumber)}</title>
<style>
  ${pageCss}
  * {
    box-sizing: border-box;
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
  }
  body {
    font-family: Arial, Helvetica, sans-serif;
    color: #000000;
    background: #FFFFFF;
    line-height: 1.28;
  }
  .guide {
    width: 100%;
    background: #FFFFFF;
    color: #000000;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .top-header {
    display: flex;
    justify-content: space-between;
    align-items: stretch;
    gap: 8px;
    border-bottom: 2px solid #000000;
    padding-bottom: 6px;
  }
  .brand-box {
    background: #000000;
    color: #FFFFFF;
    font-weight: 900;
    font-size: 11px;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    padding: 6px 8px;
    display: flex;
    align-items: center;
    max-width: 58%;
    word-break: break-word;
  }
  .meta-box {
    text-align: right;
    font-size: 10px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
  }
  .meta-box .num {
    font-size: 11.5px;
    font-weight: 900;
  }
  .section-box {
    border: 2px solid #000000;
    padding: 6px 8px;
  }
  .section-title {
    font-size: 9.5px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    border-bottom: 1.5px solid #000000;
    padding-bottom: 3px;
    margin-bottom: 5px;
  }
  .pay-row {
    font-size: 10px;
    font-weight: 700;
    margin-bottom: 3px;
  }
  .pay-row:last-child { margin-bottom: 0; }
  .check {
    display: inline-block;
    font-family: "Courier New", Courier, monospace;
    font-weight: 900;
    margin-right: 4px;
  }
  .info-line {
    font-size: 9.5px;
    margin-bottom: 2px;
    word-break: break-word;
  }
  .info-line:last-child { margin-bottom: 0; }
  .info-label { font-weight: 800; }
  .recipient-box {
    border: 3px solid #000000;
  }
  .recipient-bar {
    background: #000000;
    color: #FFFFFF;
    text-align: center;
    font-weight: 900;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 5px 6px;
  }
  .recipient-body {
    padding: 7px 9px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .recipient-name-block {
    border-bottom: 1.5px solid #000000;
    padding-bottom: 4px;
    margin-bottom: 2px;
  }
  .recipient-name-label {
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
    display: block;
  }
  .recipient-name {
    font-size: 16px;
    font-weight: 900;
    text-transform: uppercase;
    line-height: 1.15;
    word-break: break-word;
  }
  .recipient-row {
    font-size: 11px;
    line-height: 1.25;
    word-break: break-word;
  }
  .recipient-row .lbl {
    font-weight: 900;
    text-transform: uppercase;
  }
  .recipient-row .val {
    font-weight: 700;
  }
  .obs-box {
    border: 1.5px solid #000000;
    padding: 5px 8px;
    font-size: 9.5px;
    min-height: 28px;
    word-break: break-word;
  }
  .obs-label {
    font-weight: 900;
    text-transform: uppercase;
  }
  .obs-blank-line {
    border-bottom: 1px solid #000000;
    height: 12px;
    margin-top: 4px;
  }
  .footer-code {
    text-align: center;
    font-family: "Courier New", Courier, monospace;
    font-size: 18px;
    font-weight: 900;
    letter-spacing: 2px;
    padding-top: 4px;
  }
</style>
</head>
<body>
  <div class="sheet">
    <div class="guide">
      <div class="top-header">
        <div class="brand-box">${escapeHtml(headerTitle)}</div>
        <div class="meta-box">
          <div class="num">GUÍA N.º: ${escapeHtml(guideNumber || '----------')}</div>
          <div><strong>FECHA:</strong> ${escapeHtml(formattedDate)}</div>
        </div>
      </div>

      <div class="section-box">
        <div class="section-title">MODALIDAD DE PAGO</div>
        <div class="pay-row"><span class="check">[ ${isOriginPaid ? 'X' : '&nbsp;'} ]</span> PAGADO EN ORIGEN (Punto de Envío)</div>
        <div class="pay-row"><span class="check">[ ${isContraEntrega ? 'X' : '&nbsp;'} ]</span> CONTRA ENTREGA (Cobro en Destino)</div>
      </div>

      <div class="section-box">
        <div class="section-title">DATOS DEL REMITENTE (ORIGEN)</div>
        <div class="info-line"><span class="info-label">Nombre:</span> ${escapeHtml(sender?.name || '—')}</div>
        <div class="info-line"><span class="info-label">NIT / Cédula:</span> ${escapeHtml(sender?.tax_id || '—')}</div>
        <div class="info-line"><span class="info-label">Celular:</span> ${escapeHtml(sender?.phone || '—')}</div>
        <div class="info-line"><span class="info-label">Email:</span> ${escapeHtml(sender?.email || '—')}</div>
        <div class="info-line"><span class="info-label">Dirección:</span> ${escapeHtml(sender?.address || '—')}</div>
        <div class="info-line"><span class="info-label">Ciudad / Depto:</span> ${escapeHtml(senderCityDept || '—')}</div>
      </div>

      <div class="recipient-box">
        <div class="recipient-bar">DATOS DEL DESTINATARIO (ENTREGA)</div>
        <div class="recipient-body">
          <div class="recipient-name-block">
            <span class="recipient-name-label">NOMBRE:</span>
            <div class="recipient-name">${escapeHtml(recipient?.name || '—')}</div>
          </div>
          <div class="recipient-row"><span class="lbl">CÉDULA:</span> <span class="val">${escapeHtml(recipient?.document_number || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">CELULAR:</span> <span class="val">${escapeHtml(recipient?.phone || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">EMAIL:</span> <span class="val">${escapeHtml(recipient?.email || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">DIRECCIÓN:</span> <span class="val">${escapeHtml(recipient?.address || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">BARRIO:</span> <span class="val">${escapeHtml(recipient?.neighborhood || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">CIUDAD:</span> <span class="val">${escapeHtml(recipient?.city || '—')}</span></div>
          <div class="recipient-row"><span class="lbl">DEPARTAMENTO:</span> <span class="val">${escapeHtml(recipient?.department || '—')}</span></div>
        </div>
      </div>

      <div class="obs-box">
        <span class="obs-label">OBSERVACIONES:</span>
        ${observations && observations.trim()
            ? `<span> ${escapeHtml(observations.trim())}</span>`
            : `<div class="obs-blank-line"></div>`
        }
      </div>

      <div class="footer-code">*${escapeHtml(guideNumber || '0000000000')}*</div>
    </div>
  </div>
</body>
</html>`;

    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow?.document;
    if (!doc) {
        document.body.removeChild(iframe);
        return;
    }

    doc.open();
    doc.write(html);
    doc.close();

    setTimeout(() => {
        try {
            iframe.contentWindow?.focus();
            iframe.contentWindow?.print();
        } finally {
            setTimeout(() => {
                if (iframe.parentNode) {
                    iframe.parentNode.removeChild(iframe);
                }
            }, 1500);
        }
    }, 150);
}

/**
 * Wrap a text string into multiple lines fitting within maxWidth on a Canvas 2D context.
 */
function wrapCanvasText(ctx, text, maxWidth) {
    const clean = String(text ?? '').trim();
    if (!clean) return ['—'];
    const words = clean.split(/\s+/);
    const lines = [];
    let current = '';

    for (const word of words) {
        const test = current ? `${current} ${word}` : word;
        if (ctx.measureText(test).width <= maxWidth) {
            current = test;
        } else {
            if (current) lines.push(current);
            // Handle very long single word
            if (ctx.measureText(word).width > maxWidth) {
                let chunk = '';
                for (const ch of word) {
                    if (ctx.measureText(chunk + ch).width > maxWidth && chunk) {
                        lines.push(chunk);
                        chunk = ch;
                    } else {
                        chunk += ch;
                    }
                }
                current = chunk;
            } else {
                current = word;
            }
        }
    }
    if (current) lines.push(current);
    return lines.length > 0 ? lines : ['—'];
}

/**
 * Draw a labeled field with automatic line wrapping and return the new Y coordinate.
 */
function drawLabeledField(ctx, label, value, x, y, maxWidth, fontSize, lineHeight) {
    ctx.font = `900 ${fontSize}px Arial, Helvetica, sans-serif`;
    const labelText = `${label} `;
    const labelWidth = ctx.measureText(labelText).width;
    ctx.fillText(labelText, x, y);

    ctx.font = `600 ${fontSize}px Arial, Helvetica, sans-serif`;
    const firstLineMax = Math.max(100, maxWidth - labelWidth);
    const rawVal = String(value ?? '').trim() || '—';

    // Split words so first line shares the row with the label, subsequent lines start at x
    const words = rawVal.split(/\s+/);
    let firstLine = '';
    let idx = 0;
    while (idx < words.length) {
        const candidate = firstLine ? `${firstLine} ${words[idx]}` : words[idx];
        if (ctx.measureText(candidate).width <= firstLineMax || !firstLine) {
            firstLine = candidate;
            idx++;
        } else {
            break;
        }
    }
    ctx.fillText(firstLine, x + labelWidth, y);
    let curY = y + lineHeight;

    if (idx < words.length) {
        const rest = words.slice(idx).join(' ');
        const restLines = wrapCanvasText(ctx, rest, maxWidth);
        for (const line of restLines) {
            ctx.fillText(line, x, curY);
            curY += lineHeight;
        }
    }

    return curY;
}

/**
 * Render the Shipping Guide onto a 1000x1400 high-resolution B&W Canvas and download as PNG.
 */
export function downloadShippingGuidePng({
    guideNumber,
    formattedDate,
    paymentMode,
    sender,
    recipient,
    observations,
}) {
    const W = 1000;
    const H = 1400;
    const M = 44; // outer margin
    const contentW = W - M * 2;

    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Pure white background
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, W, H);

    ctx.fillStyle = '#000000';
    ctx.strokeStyle = '#000000';
    ctx.textBaseline = 'top';

    let y = M;

    // 1. TOP HEADER
    const headerTitle = (sender?.shipping_header || sender?.name || 'LOGÍSTICA & ENVÍOS EXPRESS').trim().toUpperCase();
    ctx.font = '900 24px Arial, Helvetica, sans-serif';
    const brandMaxW = 500;
    const brandLines = wrapCanvasText(ctx, headerTitle, brandMaxW - 32).slice(0, 2);
    const brandH = Math.max(72, brandLines.length * 30 + 24);
    const brandW = 520;

    ctx.fillStyle = '#000000';
    ctx.fillRect(M, y, brandW, brandH);

    ctx.fillStyle = '#FFFFFF';
    let by = y + (brandH - brandLines.length * 30) / 2 + 2;
    for (const line of brandLines) {
        ctx.fillText(line, M + 18, by);
        by += 30;
    }

    // Right side meta (GUÍA N.º & FECHA)
    ctx.fillStyle = '#000000';
    ctx.textAlign = 'right';
    ctx.font = '900 24px Arial, Helvetica, sans-serif';
    ctx.fillText(`GUÍA N.º: ${guideNumber || '----------'}`, W - M, y + 10);
    ctx.font = '700 21px Arial, Helvetica, sans-serif';
    ctx.fillText(`FECHA: ${formattedDate}`, W - M, y + 44);
    ctx.textAlign = 'left';

    y += brandH + 14;
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(M, y);
    ctx.lineTo(W - M, y);
    ctx.stroke();

    y += 18;

    // 2. MODALIDAD DE PAGO
    const isOriginPaid = paymentMode === 'origen';
    const payBoxH = 122;
    ctx.lineWidth = 4;
    ctx.strokeRect(M, y, contentW, payBoxH);

    ctx.font = '900 20px Arial, Helvetica, sans-serif';
    ctx.fillText('MODALIDAD DE PAGO', M + 18, y + 14);
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(M + 18, y + 42);
    ctx.lineTo(W - M - 18, y + 42);
    ctx.stroke();

    ctx.font = '700 22px "Courier New", Courier, monospace';
    ctx.fillText(`[ ${isOriginPaid ? 'X' : ' '} ]`, M + 18, y + 54);
    ctx.fillText(`[ ${!isOriginPaid ? 'X' : ' '} ]`, M + 18, y + 86);

    ctx.font = '700 21px Arial, Helvetica, sans-serif';
    ctx.fillText('PAGADO EN ORIGEN (Punto de Envío)', M + 96, y + 54);
    ctx.fillText('CONTRA ENTREGA (Cobro en Destino)', M + 96, y + 86);

    y += payBoxH + 18;

    // 3. DATOS DEL REMITENTE (ORIGEN)
    const senderStartY = y;
    const senderCityDept = buildCityDept(sender?.city, sender?.department);
    let sy = y + 54;
    const senderInnerW = contentW - 36;

    // Draw content first to measure exact height, then stroke box
    ctx.font = '900 20px Arial, Helvetica, sans-serif';
    ctx.fillText('DATOS DEL REMITENTE (ORIGEN)', M + 18, y + 14);
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(M + 18, y + 42);
    ctx.lineTo(W - M - 18, y + 42);
    ctx.stroke();

    sy = drawLabeledField(ctx, 'Nombre:', sender?.name || '—', M + 18, sy, senderInnerW, 20, 29);
    sy = drawLabeledField(ctx, 'NIT / Cédula:', sender?.tax_id || '—', M + 18, sy, senderInnerW, 20, 29);
    sy = drawLabeledField(ctx, 'Celular:', sender?.phone || '—', M + 18, sy, senderInnerW, 20, 29);
    sy = drawLabeledField(ctx, 'Email:', sender?.email || '—', M + 18, sy, senderInnerW, 20, 29);
    sy = drawLabeledField(ctx, 'Dirección:', sender?.address || '—', M + 18, sy, senderInnerW, 20, 29);
    sy = drawLabeledField(ctx, 'Ciudad / Depto:', senderCityDept || '—', M + 18, sy, senderInnerW, 20, 29);

    const senderBoxH = sy - senderStartY + 12;
    ctx.lineWidth = 4;
    ctx.strokeRect(M, senderStartY, contentW, senderBoxH);

    y = senderStartY + senderBoxH + 20;

    // 4. DATOS DEL DESTINATARIO (ENTREGA) — HERO BLOCK
    const recipStartY = y;
    const barH = 52;
    ctx.fillStyle = '#000000';
    ctx.fillRect(M, recipStartY, contentW, barH);

    ctx.fillStyle = '#FFFFFF';
    ctx.font = '900 24px Arial, Helvetica, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('DATOS DEL DESTINATARIO (ENTREGA)', W / 2, recipStartY + 13);
    ctx.textAlign = 'left';

    ctx.fillStyle = '#000000';
    let ry = recipStartY + barH + 18;
    const recipInnerW = contentW - 40;

    // NOMBRE (extra large bold uppercase)
    ctx.font = '900 18px Arial, Helvetica, sans-serif';
    ctx.fillText('NOMBRE:', M + 20, ry);
    ry += 24;

    ctx.font = '900 34px Arial, Helvetica, sans-serif';
    const nameLines = wrapCanvasText(ctx, (recipient?.name || '—').toUpperCase(), recipInnerW).slice(0, 2);
    for (const nLine of nameLines) {
        ctx.fillText(nLine, M + 20, ry);
        ry += 40;
    }

    // Divider under name
    ry += 4;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(M + 20, ry);
    ctx.lineTo(W - M - 20, ry);
    ctx.stroke();
    ry += 16;

    // Recipient details (large, clear for couriers)
    ry = drawLabeledField(ctx, 'CÉDULA:', recipient?.document_number || '—', M + 20, ry, recipInnerW, 24, 35);
    ry = drawLabeledField(ctx, 'CELULAR:', recipient?.phone || '—', M + 20, ry, recipInnerW, 24, 35);
    ry = drawLabeledField(ctx, 'EMAIL:', recipient?.email || '—', M + 20, ry, recipInnerW, 23, 34);
    ry = drawLabeledField(ctx, 'DIRECCIÓN:', recipient?.address || '—', M + 20, ry, recipInnerW, 24, 35);
    ry = drawLabeledField(ctx, 'BARRIO:', recipient?.neighborhood || '—', M + 20, ry, recipInnerW, 24, 35);
    ry = drawLabeledField(ctx, 'CIUDAD:', recipient?.city || '—', M + 20, ry, recipInnerW, 24, 35);
    ry = drawLabeledField(ctx, 'DEPARTAMENTO:', recipient?.department || '—', M + 20, ry, recipInnerW, 24, 35);

    const recipBoxH = ry - recipStartY + 14;
    ctx.lineWidth = 6;
    ctx.strokeRect(M, recipStartY, contentW, recipBoxH);

    y = recipStartY + recipBoxH + 18;

    // 5. OBSERVACIONES
    const footerReservedH = 74;
    const obsMaxH = Math.max(76, H - M - footerReservedH - y);
    ctx.lineWidth = 3;
    ctx.strokeRect(M, y, contentW, obsMaxH);

    const obsClean = String(observations || '').trim();
    if (obsClean) {
        drawLabeledField(ctx, 'OBSERVACIONES:', obsClean, M + 18, y + 14, contentW - 36, 20, 28);
    } else {
        ctx.font = '900 20px Arial, Helvetica, sans-serif';
        ctx.fillText('OBSERVACIONES:', M + 18, y + 14);
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(M + 18, y + obsMaxH - 18);
        ctx.lineTo(W - M - 18, y + obsMaxH - 18);
        ctx.stroke();
    }

    // 6. FOOTER GUIDE NUMBER (*1000000001*)
    ctx.fillStyle = '#000000';
    ctx.font = '900 38px "Courier New", Courier, monospace';
    ctx.textAlign = 'center';
    ctx.fillText(`*${guideNumber || '0000000000'}*`, W / 2, H - M - 48);
    ctx.textAlign = 'left';

    const slug = slugifyFilename(recipient?.name);
    const numSlug = String(guideNumber || 'guia').replace(/[^a-zA-Z0-9_-]/g, '');
    const link = document.createElement('a');
    link.download = `guia-envio-${slug}-${numSlug}.png`;
    link.href = canvas.toDataURL('image/png');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
