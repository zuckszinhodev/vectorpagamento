/**
 * Validates that a URL uses a safe protocol (http/https) to prevent
 * javascript: URIs, data: URIs, and other dangerous schemes.
 */
export function isSafeUrl(url: string): boolean {
  try {
    const parsed = new URL(url, window.location.origin);
    return ['http:', 'https:'].includes(parsed.protocol);
  } catch {
    return false;
  }
}

/**
 * Returns the URL if it's safe, otherwise returns '#'.
 */
export function safeHref(url: string): string {
  return isSafeUrl(url) ? url : '#';
}

/**
 * Safe wrapper for window.open that validates URL protocol.
 */
export function safeWindowOpen(url: string) {
  if (isSafeUrl(url)) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}
