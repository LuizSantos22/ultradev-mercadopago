/**
 * UltraDev MercadoPago Checkout
 * MP SDK v2 + cardForm — CC com 3DS, Pix, Boleto, Checkout Pro
 * Compatível com Prototype.js (OpenMage/Magento 1)
 * Integração correta com Moip OSC (onestep_form / .moip-place-order)
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

    var mp            = null;
    var cardForm      = null;
    var currentSubmethod = 'cc';

    // Flag que evita loop: após tokenizar, a próxima chamada ao interceptor
    // já tem o token pronto e deve deixar o submit prosseguir normalmente.
    var _tokenized = false;

    // Callback a chamar depois que o token estiver pronto (resolve a Promise do interceptor)
    var _resolveSubmit = null;

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
        _hookMoipOSC();
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
        _tokenized = false; // troca de aba zera o token anterior

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
                    cardholderName:       { id: 'ump-cardholderName',    placeholder: 'Nome no cartão' },
                    cardNumber:           { id: 'ump-cardNumber',         placeholder: '0000 0000 0000 0000' },
                    cardExpirationMonth:  { id: 'ump-cardExpMonth',       placeholder: 'MM' },
                    cardExpirationYear:   { id: 'ump-cardExpYear',        placeholder: 'AAAA' },
                    securityCode:         { id: 'ump-securityCode',       placeholder: 'CVV' },
                    installments:         { id: 'ump-installments-select' },
                    identificationType:   { id: 'ump-doc-type-select' },
                    identificationNumber: { id: 'ump-doc-number-input',   placeholder: 'Ex: 00000000000' },
                    issuer:               { id: 'ump-issuer-select' }
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
                            if (_resolveSubmit) { _resolveSubmit(false); _resolveSubmit = null; }
                            return;
                        }
                        _fillHiddenFields(token);
                        _tokenized = true;
                        if (_resolveSubmit) { _resolveSubmit(true); _resolveSubmit = null; }
                    },
                    onError: function (errors) {
                        if (!errors || !errors.length) return;
                        _showError(errors.map(function (e) { return e.message; }).join(' '));
                        _setLoading(false);
                        if (_resolveSubmit) { _resolveSubmit(false); _resolveSubmit = null; }
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
        _tokenized = false;
    }

    // ── Integração com Moip OSC ───────────────────────────────────
    //
    // O OSC usa:
    //   form#onestep_form (serializado via jQuery)
    //   botão .moip-place-order → updateOrderMethod() → POST AJAX
    //
    // Não existe OnestepcheckoutForm.prototype.placeOrder aqui.
    // A estratégia correta é interceptar o clique no botão ANTES de
    // updateOrderMethod() ser chamado, gerar o token, preencher os
    // hidden fields (que farão parte do serialize()) e só então
    // deixar o OSC continuar.

    function _hookMoipOSC() {
        // Aguarda o DOM estar pronto e o OSC ter bindado seus próprios handlers.
        // Usamos delegação no document com stopImmediatePropagation para
        // interceptar antes do handler original do OSC.
        setTimeout(function () {
            if (typeof jQuery === 'undefined') return;

            jQuery(document).on('click', '.moip-place-order', function (e) {
                var selectedMethod = jQuery('input[name="payment[method]"]:checked').val();
                if (selectedMethod !== cfg.methodCode) return; // outro método — deixa passar

                e.stopImmediatePropagation(); // bloqueia o handler original do OSC
                e.preventDefault();

                _clearError();

                switch (currentSubmethod) {
                    case 'cc':
                        _handleCcBeforeSubmit();
                        break;
                    case 'pix':
                        if (_validateTicket('pix')) _triggerOscSubmit();
                        break;
                    case 'boleto':
                        if (_validateTicket('boleto')) _triggerOscSubmit();
                        break;
                    case 'checkout_pro':
                        _triggerOscSubmit();
                        break;
                    default:
                        _triggerOscSubmit();
                }
            });

        }, 500);
    }

    // ── CC: tokeniza e só então dispara o OSC ─────────────────────

    function _handleCcBeforeSubmit() {
        if (_tokenized) {
            // Token já gerado neste ciclo — continua direto
            _triggerOscSubmit();
            return;
        }

        if (!cardForm) {
            _showError('Formulário do cartão não inicializado. Recarregue a página.');
            return;
        }

        _setLoading(true);

        var promise = new Promise(function (resolve) {
            _resolveSubmit = resolve;
        });

        cardForm.createCardToken();

        promise.then(function (success) {
            _setLoading(false);
            if (success) {
                _triggerOscSubmit();
            }
        });
    }

    function _fillHiddenFields(token) {
        var data = cardForm.getCardFormData();

        _setHidden(SEL.hiddenToken,     token.id || '');
        _setHidden(SEL.hiddenPmId,      data.paymentMethodId || '');
        _setHidden(SEL.hiddenInstall,   data.selectedQuantity || 1);
        _setHidden(SEL.hiddenIssuerId,  (data.issuer && data.issuer.id) ? data.issuer.id : '');
        _setHidden(SEL.hiddenCardName,  data.cardholderName || '');
        _setHidden(SEL.hiddenTruncCard, 'xxxx xxxx xxxx ' + (token.last_four_digits || ''));
        _setHidden(SEL.hiddenDocType,   data.identificationType || 'CPF');
        _setHidden(SEL.hiddenDocNum,    (data.identificationNumber || '').replace(/\D/g, ''));
    }

    // ── Pix / Boleto ─────────────────────────────────────────────

    function _validateTicket(type) {
        var docTypeSel = type === 'pix' ? SEL.pixDocType : SEL.boletoDocType;
        var docNumSel  = type === 'pix' ? SEL.pixDocNum  : SEL.boletoDocNum;
        var docType    = document.querySelector(docTypeSel);
        var docNum     = document.querySelector(docNumSel);

        if (!docNum || !docNum.value.replace(/\D/g, '')) {
            _showError('Informe o CPF/CNPJ para pagamento via ' + (type === 'pix' ? 'Pix' : 'Boleto') + '.');
            return false;
        }

        _setHidden(SEL.hiddenDocType, docType ? docType.value : 'CPF');
        _setHidden(SEL.hiddenDocNum,  docNum.value.replace(/\D/g, ''));
        return true;
    }

    // ── Dispara o submit do OSC manualmente ───────────────────────
    // Replica exatamente o que o handler original do .moip-place-order faz:
    // valida o VarienForm e chama updateOrderMethod()

    function _triggerOscSubmit() {
        if (typeof VarienForm !== 'undefined' && typeof updateOrderMethod === 'function') {
            var form = new VarienForm('onestep_form', true);
            if (form.validator && form.validator.validate()) {
                updateOrderMethod();
            } else {
                if (typeof visibilyloading === 'function') visibilyloading('end');
                if (typeof getErroDescription === 'function') getErroDescription();
                if (typeof jQuery !== 'undefined') {
                    jQuery('#ErrosFinalizacao').modal();
                    jQuery('.moip-place-order').show();
                    jQuery('.validation-advice').delay(5000).fadeOut('slow');
                }
            }
            return;
        }

        // Fallback genérico
        var btn = document.querySelector('.moip-place-order, #review-buttons-container .btn-checkout, button.btn-checkout');
        if (btn) {
            var clone = btn.cloneNode(true);
            btn.parentNode.replaceChild(clone, btn);
            clone.click();
        }
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
                } catch (e) { /* ignora erro de parse */ }
            };
            xhr.send();
        }, 3000);
        return interval;
    }

    // ── Reinit após refresh AJAX do bloco de pagamento ────────────
    // Chamado pelo OSC após recarregar #payment-method-available (ex: troca de frete)

    function reinit() {
        _destroyCardForm();
        _tokenized = false;
        if (currentSubmethod === 'cc') {
            _initCardForm();
        }
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
        reinit:              reinit,
        startThreeDsPolling: startThreeDsPolling
    };

}());
