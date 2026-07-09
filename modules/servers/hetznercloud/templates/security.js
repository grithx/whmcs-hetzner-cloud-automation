(function () {
    'use strict';

    function addCsrfToken(form) {
        if (!form || String(form.method || '').toLowerCase() !== 'post') {
            return;
        }

        const token = window.hetznerCloudCsrfToken;
        if (!token) {
            return;
        }

        let input = form.querySelector('input[name="hetznercloud_csrf_token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'hetznercloud_csrf_token';
            form.appendChild(input);
        }
        input.value = token;
    }

    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        addCsrfToken(this);
        return nativeSubmit.call(this);
    };

    document.addEventListener('submit', function (event) {
        addCsrfToken(event.target);
    }, true);
})();
