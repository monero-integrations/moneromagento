define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MoneroIntegrations_Custompayment/payment/custompayment'
        },

        redirectAfterPlaceOrder: false,

        afterPlaceOrder: function () {
            window.location.replace(url.build('moneropayment/Gateway/MoneroPayment'));
        }
    });
});
