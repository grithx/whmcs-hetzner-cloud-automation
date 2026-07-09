(function () {
    'use strict';

    function getToken() {
        return window.hetznerCloudCsrfToken || '';
    }

    function addCsrfToken(form) {
        if (!form || String(form.method || '').toLowerCase() !== 'post') {
            return;
        }

        const csrfToken = getToken();
        if (!csrfToken) {
            return;
        }

        let input = form.querySelector('input[name="hetznercloud_csrf_token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'hetznercloud_csrf_token';
            form.appendChild(input);
        }
        input.value = csrfToken;
    }

    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        addCsrfToken(this);
        return nativeSubmit.call(this);
    };

    document.addEventListener('submit', function (event) {
        addCsrfToken(event.target);
    }, true);

    // Existing UI uses GET for ISO cache refresh. Transparently upgrade only that
    // request to a CSRF-protected POST without changing the user workflow.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');

        if (url.includes('ajax=refresh_isos')) {
            const cleanUrl = url
                .replace(/([?&])ajax=refresh_isos(&|$)/, function (_, prefix, suffix) {
                    if (prefix === '?' && suffix === '&') return '?';
                    if (prefix === '&' && suffix === '&') return '&';
                    return '';
                })
                .replace(/[?&]$/, '');

            const body = new URLSearchParams();
            body.set('ajax', 'refresh_isos');
            body.set('hetznercloud_csrf_token', getToken());

            return nativeFetch(cleanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            });
        }

        return nativeFetch(input, init);
    };
})();
