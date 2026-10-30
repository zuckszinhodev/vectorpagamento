function csvEscape(value: unknown): string {
  if (value === null || value === undefined) {
    return '""';
  }

  const stringValue = Array.isArray(value)
    ? value.map((entry) => String(entry)).join(' | ')
    : String(value);

  // Texto vindo de comprador ou seller não pode virar fórmula ao abrir no Excel
  const safeValue = /^[=+\-@\t\r]/.test(stringValue) && typeof value !== 'number' ? `'${stringValue}` : stringValue;

  return `"${safeValue.replace(/"/g, '""')}"`;
}

export function downloadCsvFile(fileName: string, headers: string[], rows: unknown[][]): void {
  if (typeof window === 'undefined') {
    return;
  }

  const csvBody = [
    headers.map((header) => csvEscape(header)).join(';'),
    ...rows.map((row) => row.map((cell) => csvEscape(cell)).join(';')),
  ].join('\n');

  const csvWithBom = `﻿${csvBody}`;
  const blob = new Blob([csvWithBom], { type: 'text/csv;charset=utf-8;' });
  const downloadUrl = window.URL.createObjectURL(blob);
  const link = window.document.createElement('a');

  link.href = downloadUrl;
  link.download = fileName;
  link.style.display = 'none';

  window.document.body.appendChild(link);
  link.click();
  window.document.body.removeChild(link);
  window.URL.revokeObjectURL(downloadUrl);
}
