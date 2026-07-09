define(['qrcodeGenerator', 'jquery', 'mage/translate'], function (qrcode, $, $t) {
    'use strict';

    return function (config, element) {
        var uri = element.getAttribute('data-monero-uri');
        var state = element.getAttribute('data-monero-state');
        var statusUrl = element.getAttribute('data-monero-status-url');
        var successUrl = element.getAttribute('data-monero-success-url');
        var target = element.querySelector('.monero-payment-qr');
        var statusMessage = element.querySelector('.monero-payment-status');
        var receivedAmount = element.querySelector('[data-monero-received]');
        var remainingAmount = element.querySelector('[data-monero-remaining]');
        var pollTimer = null;
        var redirectTimer = null;
        var requestInFlight = false;

        function renderQr(nextUri) {
            if (!nextUri || !target || typeof qrcode !== 'function') {
                return;
            }
            target.style.display = '';
            target.innerHTML = '';
            var qr = qrcode(0, 'M');
            try {
                qr.addData(nextUri);
                qr.make();
                target.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
            } catch (error) {
                target.innerHTML = '';
            }
        }

        function redirectWhenPaid(nextSuccessUrl) {
            if (redirectTimer || !nextSuccessUrl) {
                return;
            }
            redirectTimer = setTimeout(function () {
                window.location.href = nextSuccessUrl;
            }, 3000);
        }

        function setStatus(nextState) {
            state = nextState || state;
            element.setAttribute('data-monero-state', state);
            if (target && state !== 'paid' && state !== 'overpaid' && state !== 'detected') {
                target.style.display = '';
            }
            if (!statusMessage) {
                return;
            }
            if (state === 'paid' || state === 'overpaid') {
                statusMessage.className = 'monero-payment-status monero-paid';
                statusMessage.textContent = $t('Payment received. Thank you! Returning you to the order confirmation page.');
                if (target) {
                    target.style.display = 'none';
                }
            } else if (state === 'detected') {
                statusMessage.className = 'monero-payment-status monero-detected';
                statusMessage.textContent = $t('Payment detected. Waiting for the required confirmations.');
                if (target) {
                    target.style.display = 'none';
                }
            } else if (state === 'partial') {
                statusMessage.className = 'monero-payment-status monero-waiting';
                statusMessage.textContent = $t('Partial payment received. Please send the remaining amount to the same address.');
            } else {
                statusMessage.className = 'monero-payment-status monero-waiting';
                statusMessage.textContent = $t('Waiting for payment. This page updates automatically.');
            }
        }

        function setPartialDetails(data) {
            var details = element.querySelectorAll('.monero-payment-partial-detail');
            var showDetails = state === 'partial';

            for (var index = 0; index < details.length; index++) {
                if (showDetails) {
                    details[index].className = details[index].className.replace(/\s*monero-hidden/g, '');
                } else if (details[index].className.indexOf('monero-hidden') === -1) {
                    details[index].className += ' monero-hidden';
                }
            }
            if (receivedAmount && typeof data.received_xmr !== 'undefined') {
                receivedAmount.textContent = data.received_xmr + ' XMR';
            }
            if (remainingAmount && typeof data.remaining_xmr !== 'undefined') {
                remainingAmount.textContent = data.remaining_xmr + ' XMR';
            }
        }

        function requestStatus(onSuccess, onFailure) {
            if (typeof fetch === 'function') {
                fetch(statusUrl, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('Monero status request failed.');
                    }
                    return response.json();
                }).then(onSuccess).catch(onFailure);
                return;
            }

            if ($ && typeof $.ajax === 'function') {
                $.ajax({
                    url: statusUrl,
                    method: 'GET',
                    cache: false,
                    dataType: 'json',
                    headers: {
                        'Accept': 'application/json'
                    }
                }).done(onSuccess).fail(onFailure);
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('GET', statusUrl, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300) {
                    onFailure();
                    return;
                }
                try {
                    onSuccess(JSON.parse(xhr.responseText));
                } catch (error) {
                    onFailure();
                }
            };
            xhr.send();
        }

        function schedulePoll() {
            if (!statusUrl || pollTimer || requestInFlight || document.hidden) {
                return;
            }
            pollTimer = setTimeout(pollStatus, 10000);
        }

        function handleStatus(data) {
            if (!data || data.is_error) {
                schedulePoll();
                return;
            }
            setStatus(data.state);
            setPartialDetails(data);
            if (data.success_url) {
                successUrl = data.success_url;
            }
            if (data.monero_uri && data.monero_uri !== uri) {
                uri = data.monero_uri;
                element.setAttribute('data-monero-uri', uri);
                renderQr(uri);
            }
            if (data.paid || data.state === 'paid' || data.state === 'overpaid') {
                redirectWhenPaid(successUrl);
                return;
            }
            schedulePoll();
        }

        function pollStatus() {
            pollTimer = null;
            if (!statusUrl) {
                return;
            }
            if (requestInFlight) {
                schedulePoll();
                return;
            }
            requestInFlight = true;
            requestStatus(function (data) {
                requestInFlight = false;
                handleStatus(data);
            }, function () {
                requestInFlight = false;
                schedulePoll();
            });
        }

        renderQr(uri);
        setStatus(state);
        if (state === 'paid' || state === 'overpaid') {
            redirectWhenPaid(successUrl);
        } else {
            schedulePoll();
        }

        window.addEventListener('beforeunload', function () {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            if (redirectTimer) {
                clearTimeout(redirectTimer);
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && state !== 'paid' && state !== 'overpaid') {
                schedulePoll();
            }
        });
    };
});
