define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'linkpoint',
                component: 'Raveinfosys_Linkpoint/js/view/payment/method-renderer/linkpoint'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);