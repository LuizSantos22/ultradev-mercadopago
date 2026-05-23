/**
 * UltraDev MercadoPago Checkout
 * MP SDK v2 + cardForm — CC com 3DS, Pix, Boleto, Checkout Pro
 * Compatível com Prototype.js (OpenMage/Magento 1)
 */

var UltraDevMP = (function () {

    'use strict';

    var cfg = {
        publicKey:       '',
        siteId:          'MLB',
        amount:          0,
        installmentsUrl: '',
        statusUrl:       '',
        methodCode:      'ultradev_mercadopago',
        isSandbox:       false
    };

    var mp       = null;
    var cardForm = null;
    var currentSubmethod = 'cc';

    var SEL = {
        tabs:            '.ump-tab',
        panels:          '.ump-panel',
        hiddenToken:     '#ump-token',
        hiddenPmId:      '#ump-payment_method_id',
        hiddenInstall:   '#ump-installments',
        hiddenIssuerId:  '#ump-issuer_id',
        hiddenCardName:  '#ump-cardholderName',
        hiddenTruncCard: '#ump-trunc_card',
        hiddenDocType:   '#ump-doc_type',
        hiddenDocNum:    '#ump-doc_number',
        hiddenSubmethod: '#ump-mp_submethod',
        hiddenDevSess:   '#ump-mp_device_session_id',
        pixDocType:      '#ump-pix-doc_type',
        pixDocNum:       '#ump-pix-doc_number',
        boletoDocType:   '#ump-boleto-doc_type',
        boletoDocNum:    '#ump-boleto-doc_number',
        errorBox:        '#ump-error-box',
        loadingBox:      '#ump-loading-box'
    };

    // ── Inicialização ─────────────────────────────────────────────

    function init(config) {
        Object.assign(cfg, config);

        if (!window.MercadoPago) {
            console.error('[UltraDevMP] MercadoPago SDK v2 não carregado.');
            return;
        }

        mp = new MercadoPago(cfg.publicKey, { locale: 'pt-BR' });

        _bindTabs();
        _activateTab('cc');
    }

    // ── Tabs ──────────────────────────────────────────────────────

    function _bindTabs() {
        document.querySelectorAll(SEL.tabs).forEach(function (tab) {
            tab.addEventListener('click', function () {
                _activateTab(tab.dataset.submethod);
            });
        });
    }

    function _activateTab(sub) {
        currentSubmethod = sub;

        document.querySelectorAll(SEL.tabs).forEach(function (t) {
            t.classList.toggle('ump-tab--active', t.dataset.submethod === sub);
        });
        document.querySelectorAll(SEL.panels).forEach(function (p) {
            p.style.display = p.dataset.panel === sub ? '' : 'none';
        });

        _setHidden(SEL.hiddenSubmethod, sub);

        if (sub === 'cc') {
            _initCardForm();
        } else {
            _destroyCardForm();
        }
    }

    // ── CardForm (Secure Fields MP SDK v2) ───────────────────────

    function _initCardForm() {
        if (cardForm) return;

        try {
            cardForm = mp.cardForm({
                amount: String(cfg.amount),
                autoMount: true,
                form: {
                    id: 'ump-cardform',
                    cardholderName:      { id: 'ump-cardholderName',   placeholder: 'Nome no cartão' },
                    cardNumber:          { id: 'ump-cardNumber',        placeholder: '0000 0000 0000 0000' },
                    cardExpirationMonth: { id: 'ump-cardExpMonth',      placeholder: 'MM' },
                    cardExpirationYear:  { id: 'ump-cardExpYear',       placeholder: 'AAAA' },
                    securityCode:        { id: 'ump-securityCode',      placeholder: 'CVV' },
                    installments:        { id: 'ump-installments-select' },
                    identificationType:  { id: 'ump-doc-type-select' },
                    identificationNumber:{ id: 'ump-doc-number-input',  placeholder: 'Ex: 00000000000' },
                    issuer:              { id: 'ump-issuer-select' }
                },
                callbacks: {
                    onFormMounted: function (error) {
                        if (error) console.warn('[UltraDevMP] cardForm mount error', error);
                    },
                    onPaymentMethodsReceived: function (error, pms) {
                        if (error || !pms || !pms.length) return;
                        _showCardBrand(pms[0].thumbnail);
                    },
                    onCardTokenReceived: function (error, token) {
                        if (error) {
                            _showError(_friendlyError(error));
                            _setLoading(false);
                            return;
                        }
                        _fillHiddenAndSubmit(token);
                    },
                    onError: function (errors) {
                        if (!errors || !errors.length) return;
                        _showError(errors.map(function (e) { return e.message; }).join(' '));
                        _setLoading(false);
                    }
                }
            });
        } catch (e) {
            console.error('[UltraDevMP] cardForm init error', e);
        }
    }

    function _destroyCardForm() {
        if (cardForm) {
            try { cardForm.unmount(); } catch (e) {}
            cardForm = null;
        }
    }

    // ── Ponto de entrada público — chamado pelo botão do OSC ─────
    // O checkout chama payment.save() ou dispara o submit do form.
    // Interceptamos aqui antes de o form ser enviado.

    function beforeSubmit() {
        _clearError();
        _setLoading(true);

        switch (currentSubmethod) {
            case 'cc':
                _submitCc();
                return false; // bloqueia submit; callback do cardForm vai liberar
            case 'pix':
                return _submitPix();
            case 'boleto':
                return _submitBoleto();
            case 'checkout_pro':
                return true; // deixa o checkout seguir normalmente
            default:
                _setLoading(false);
                return true;
        }
    }

    // ── CC ────────────────────────────────────────────────────────

    function _submitCc() {
        if (!cardForm) {
            _showError('Formulário do cartão não inicializado. Recarregue a página.');
            _setLoading(false);
            return;
        }
        cardForm.createCardToken();
        // continua em onCardTokenReceived
    }

    function _fillHiddenAndSubmit(token) {
        var data = cardForm.getCardFormData();

        _setHidden(SEL.hiddenToken,     token.id || '');
        _setHidden(SEL.hiddenPmId,      data.paymentMethodId || '');
        _setHidden(SEL.hiddenInstall,   data.selectedQuantity || 1);
        _setHidden(SEL.hiddenIssuerId,  (data.issuer && data.issuer.id) ? data.issuer.id : '');
        _setHidden(SEL.hiddenCardName,  data.cardholderName || '');
        _setHidden(SEL.hiddenTruncCard, 'xxxx xxxx xxxx ' + (token.last_four_digits || ''));
        _setHidden(SEL.hiddenDocType,   data.identificationType || 'CPF');
        _setHidden(SEL.hiddenDocNum,    (data.identificationNumber || '').replace(/\D/g, ''));

        _triggerCheckoutSubmit();
    }

    // ── PIX ───────────────────────────────────────────────────────

    function _submitPix() {
        var docType = document.querySelector(SEL.pixDocType);
        var docNum  = document.querySelector(SEL.pixDocNum);

        if (!docNum || !docNum.value.replace(/\D/g, '')) {
            _showError('Informe o CPF/CNPJ para pagamento via Pix.');
            _setLoading(false);
            return false;
        }

        _setHidden(SEL.hiddenDocType, docType ? docType.value : 'CPF');
        _setHidden(SEL.hiddenDocNum,  docNum.value.replace(/\D/g, ''));
        return true;
    }

    // ── BOLETO ────────────────────────────────────────────────────

    function _submitBoleto() {
        var docType = document.querySelector(SEL.boletoDocType);
        var docNum  = document.querySelector(SEL.boletoDocNum);

        if (!docNum || !docNum.value.replace(/\D/g, '')) {
            _showError('Informe o CPF/CNPJ para geração do Boleto.');
            _setLoading(false);
            return false;
        }

        _setHidden(SEL.hiddenDocType, docType ? docType.value : 'CPF');
        _setHidden(SEL.hiddenDocNum,  docNum.value.replace(/\D/g, ''));
        return true;
    }

    // ── Integração OSC (Moip One Step Checkout) ───────────────────
    // O OSC usa OnestepcheckoutForm.prototype.placeOrder
    // Mesmo padrão que o MPv1.js original usava

    function _triggerCheckoutSubmit() {
        // Moip OSC
        if (typeof OnestepcheckoutForm === 'function') {
            var form = $$('form#onestepcheckout-form')[0]
                    || document.getElementById('onestepcheckout-form');
            if (form) {
                // dispara o submit nativo — o OSC vai capturar
                var event = document.createEvent('Event');
                event.initEvent('submit', true, true);
                form.dispatchEvent(event);
                return;
            }
        }

        // Onepage padrão
        if (window.checkout && typeof window.checkout.save === 'function') {
            window.checkout.save();
            return;
        }

        // Fallback: clica no botão place order
        var btn = document.querySelector(
            '#onestepcheckout-place-order-button, ' +
            '#review-buttons-container .btn-checkout, ' +
            'button.btn-checkout'
        );
        if (btn) btn.click();
    }

    // ── 3DS Challenge polling ─────────────────────────────────────

    function startThreeDsPolling(paymentId, redirectOnApproval) {
        var interval = setInterval(function () {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', cfg.statusUrl + '?payment_id=' + paymentId, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.status === 'approved') {
                        clearInterval(interval);
                        window.location.href = redirectOnApproval;
                    } else if (data.status === 'rejected' || data.status === 'cancelled') {
                        clearInterval(interval);
                        alert('Pagamento recusado. Por favor, tente com outro cartão.');
                    }
                    // pending_challenge: continua aguardando
                } catch (e) { /* ignora erro de parse */ }
            };
            xhr.send();
        }, 3000);
        return interval;
    }

    // ── UI helpers ────────────────────────────────────────────────

    function _setHidden(selector, value) {
        var el = document.querySelector(selector);
        if (el) el.value = value;
    }

    function _showError(msg) {
        var box = document.querySelector(SEL.errorBox);
        if (box) { box.innerHTML = msg; box.style.display = ''; }
    }

    function _clearError() {
        var box = document.querySelector(SEL.errorBox);
        if (box) { box.innerHTML = ''; box.style.display = 'none'; }
    }

    function _setLoading(state) {
        var box = document.querySelector(SEL.loadingBox);
        if (box) box.style.display = state ? '' : 'none';
    }

    function _showCardBrand(thumbnailUrl) {
        var img = document.getElementById('ump-card-brand');
        if (img && thumbnailUrl) { img.src = thumbnailUrl; img.style.display = 'inline'; }
    }

    function _friendlyError(error) {
        var map = {
            '205':  'Número do cartão não informado.',
            'E301': 'Número do cartão inválido.',
            '208':  'Data de expiração inválida.',
            '209':  'Data de expiração inválida.',
            '325':  'Data de expiração inválida.',
            '326':  'Data de expiração inválida.',
            '221':  'Nome do titular não informado.',
            '316':  'Nome do titular inválido.',
            '224':  'CVV não informado.',
            'E302': 'CVV inválido.',
            'E203': 'CVV inválido.',
            '212':  'Tipo de documento não informado.',
            '214':  'Número do documento não informado.',
            '324':  'Número do documento inválido.',
            '220':  'Emissor não informado.'
        };

        if (Array.isArray(error)) {
            return error.map(function (e) {
                return map[e.code] || e.message || ('Erro ' + e.code);
            }).join(' ');
        }

        return map[error.code] || error.message || 'Erro no processamento do cartão.';
    }

    // ── API pública ───────────────────────────────────────────────

    return {
        init:                init,
        beforeSubmit:        beforeSubmit,
        startThreeDsPolling: startThreeDsPolling
    };

}());

// ── Integração com Moip OSC ───────────────────────────────────────
// Mesmo padrão do MPv1.js original que já funcionava no seu OSC
document.addEventListener('DOMContentLoaded', function () {
    if (typeof OnestepcheckoutForm !== 'function') return;

    var _originalPlaceOrder = OnestepcheckoutForm.prototype.placeOrder;

    OnestepcheckoutForm.prototype.placeOrder = function () {
        var selectedMethod = (function () {
            var el = document.querySelector('input[name="payment[method]"]:checked');
            return el ? el.value : '';
        }());

        if (selectedMethod !== 'ultradev_mercadopago') {
            return _originalPlaceOrder.apply(this, arguments);
        }

        var canProceed = UltraDevMP.beforeSubmit();

        // Para CC: beforeSubmit retorna false (aguarda tokenização assíncrona)
        // Para Pix/Boleto/Pro: retorna true — segue o fluxo normal do OSC
        if (canProceed === false) return;

        return _originalPlaceOrder.apply(this, arguments);
    };
});
