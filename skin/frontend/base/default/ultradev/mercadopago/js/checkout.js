/**
 * UltraDev MercadoPago – checkout.js
 * Carrega o MercadoPago.js v2 dinamicamente
 */
(function () {
    'use strict';

    function loadMPScript(publicKey) {
        if (document.getElementById('mp-sdk')) return;
        var s = document.createElement('script');
        s.id  = 'mp-sdk';
        s.src = 'https://sdk.mercadopago.com/js/v2';
        s.onload = function () {
            window._mpPublicKey = publicKey;
        };
        document.head.appendChild(s);
    }

    // A public key é injetada pelo block Form/Cc via atributo data
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('mp-cc-form');
        if (el && el.dataset.publicKey) {
            loadMPScript(el.dataset.publicKey);
        }
    });
})();
