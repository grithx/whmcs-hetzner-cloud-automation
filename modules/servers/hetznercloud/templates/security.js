(function () {
    'use strict';

    function getToken() {
        return window.hetznerCloudCsrfToken || '';
    }

    function getServiceId() {
        const element = document.getElementById('serviceId');
        return element ? String(element.getAttribute('data-id') || '') : '';
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

    // Override the legacy renderer. Remote API values are inserted only through
    // textContent and event listeners; no API-controlled value reaches innerHTML.
    window.displayISOs = function (isos) {
        const isoGrid = document.getElementById('isoGrid');
        if (!isoGrid) {
            return;
        }

        isoGrid.replaceChildren();

        if (!Array.isArray(isos) || isos.length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'text-muted';
            emptyState.style.gridColumn = '1 / -1';
            emptyState.style.textAlign = 'center';
            emptyState.style.padding = '40px';
            emptyState.textContent = 'No ISOs available';
            isoGrid.appendChild(emptyState);
            return;
        }

        isos.forEach(function (iso) {
            const name = String(iso && iso.name ? iso.name : '');
            const description = String(iso && iso.description ? iso.description : name);
            const architecture = String(iso && iso.architecture ? iso.architecture : 'Unknown');
            const type = String(iso && iso.type ? iso.type : 'unknown');

            const item = document.createElement('div');
            item.className = 'iso-item';

            const header = document.createElement('div');
            header.className = 'iso-header';

            const textWrap = document.createElement('div');
            const title = document.createElement('div');
            title.className = 'iso-name';
            title.title = name;
            title.textContent = description;

            const filename = document.createElement('div');
            filename.className = 'iso-filename';
            filename.title = name;
            filename.textContent = name;

            textWrap.appendChild(title);
            textWrap.appendChild(filename);
            header.appendChild(textWrap);

            const meta = document.createElement('div');
            meta.className = 'iso-meta';

            const typeBadge = document.createElement('span');
            typeBadge.className = 'iso-badge ' + (type === 'public' ? 'public' : 'private');
            typeBadge.textContent = type;

            const architectureBadge = document.createElement('span');
            architectureBadge.className = 'iso-badge architecture';
            architectureBadge.textContent = architecture;

            meta.appendChild(typeBadge);
            meta.appendChild(architectureBadge);

            const attachButton = document.createElement('button');
            attachButton.type = 'button';
            attachButton.className = 'attach-iso-btn';
            attachButton.textContent = 'Attach ISO';
            attachButton.addEventListener('click', function () {
                if (typeof window.attachISO === 'function') {
                    window.attachISO(name);
                }
            });

            item.appendChild(header);
            item.appendChild(meta);
            item.appendChild(attachButton);
            isoGrid.appendChild(item);
        });

        if (typeof window.setupISOFilters === 'function') {
            window.setupISOFilters(isos);
        }
    };

    // Generate the short-lived console grant at click time so an idle product page
    // never consumes the grant validity window before the user opens the console.
    window.openConsole = function () {
        const serviceId = getServiceId();
        if (!serviceId || !getToken()) {
            window.alert('Console authorization is unavailable. Please reload the page.');
            return;
        }

        const popup = window.open('', 'HetznerConsole', 'width=1000,height=700,toolbar=no,location=no,status=no,menubar=no,scrollbars=no,resizable=yes');
        if (!popup) {
            window.alert('Please allow popups for this site to open the web console.');
            return;
        }

        try {
            popup.document.title = 'Hetzner Console';
            popup.document.body.textContent = 'Creating secure console session...';
        } catch (error) {
            // Navigation still proceeds even if the placeholder document cannot be updated.
        }

        const body = new URLSearchParams();
        body.set('ajax', 'console_grant');
        body.set('hetznercloud_csrf_token', getToken());

        nativeFetch('clientarea.php?action=productdetails&id=' + encodeURIComponent(serviceId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body.toString()
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Console authorization failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || data.success !== true || typeof data.url !== 'string' || data.url === '') {
                    throw new Error('Console authorization failed');
                }
                popup.location.replace(data.url);
            })
            .catch(function () {
                popup.close();
                window.alert('Unable to open the web console. Please try again.');
            });
    };
})();
