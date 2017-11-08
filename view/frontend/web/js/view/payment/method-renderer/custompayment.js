define(
       [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals',
        'Magento_Catalog/js/price-utils',
        'mage/url'
        ],
       function (
                 $,
                 Component,
                 quote,
                 totals,
                 priceUtils,
                 placeOrderAction,
                 selectPaymentMethodAction,
                 customer,
                 checkoutData,
                 additionalValidators,
                 url) {
       'use strict';
       return Component.extend({
                   defaults: {
                       template: 'MoneroIntegrations_Custompayment/payment/custompayment'
                             },
                   afterPlaceOrder: function () {
                       var total = document.getElementsByClassName('amount')[2].innerText; // TODO: use magento tools to get total instead
                       console.log(total);
                       var encodedData = window.btoa(total);
                       var gatewayPath = '/Gateway/monero-payment.php?xmr=';
                       var redirectUrl = gatewayPath.concat(encodedData);
                       console.log(redirectUrl);
                       window.location.replace(url.build(redirectUrl));
                       }
               });
       });
