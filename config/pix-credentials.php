<?php
/**
 * Credenciais de APIs
 */

// FuriaPay
if (!defined('FURIAPAY_PUBLIC_KEY')) {
    define('FURIAPAY_PUBLIC_KEY', 'furiapay_live_JORUB27mOVeZt9BsEl2F5kxwJVw0C2oh');
}
if (!defined('FURIAPAY_SECRET_KEY')) {
    define('FURIAPAY_SECRET_KEY', 'sk_live_UeZjPaiVMdCgankmYZeNp8eNGGt0mhG6');
}
if (!defined('FURIAPAY_POSTBACK_URL')) {
    define('FURIAPAY_POSTBACK_URL', '');
}
if (!defined('FURIAPAY_STATUS_URL_TEMPLATE')) {
    define('FURIAPAY_STATUS_URL_TEMPLATE', 'https://api.furiapaybr.app/v1/payment-transaction/info/{id}');
}
if (!defined('FURIAPAY_DELIVERY_URL_TEMPLATE')) {
    define('FURIAPAY_DELIVERY_URL_TEMPLATE', '');
}
if (!defined('FURIAPAY_CREATE_URL')) {
    define('FURIAPAY_CREATE_URL', 'https://api.furiapaybr.app/v1/payment-transaction/create');
}

// FusionPay (legado)
if (!defined('PIX_SECRET_KEY')) {
    define('PIX_SECRET_KEY', '');
}
if (!defined('PIX_PUBLIC_KEY')) {
    define('PIX_PUBLIC_KEY', '');
}

// Casa dos Dados (Fallback CNPJ)
if (!defined('CASA_DADOS_TOKEN')) {
    define('CASA_DADOS_TOKEN', '');
}
