function getXsrfToken() {
    const match = document.cookie.match(new RegExp('(^|;\\s*)XSRF-TOKEN=([^;]*)'));
    return match ? decodeURIComponent(match[2]) : '';
}

export async function csrfFetch(url, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getXsrfToken(),
        ...(options.headers || {}),
    };

    const metaToken = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        : null;

    if (metaToken && !headers['X-CSRF-TOKEN']) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }

    return fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });
}
