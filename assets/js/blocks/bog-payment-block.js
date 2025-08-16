/**
 * Bank of Georgia Payment Method for WooCommerce Blocks
 */

(function() {
    'use strict';
    
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { useEffect, createElement } = window.wp.element;

    const settings = getSetting('bog_payment_data', {});

    const defaultLabel = 'Bank of Georgia';
    const label = decodeEntities(settings.title || defaultLabel);

    /**
     * Content component for BOG payment method
     */
    const Content = function(props) {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;
        
        useEffect(function() {
            const unsubscribe = onPaymentSetup(function() {
                // Return true to proceed with the payment
                // The actual redirect will be handled by the server-side process_payment method
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            bog_payment: true,
                        },
                    },
                };
            });
            
            return unsubscribe;
        }, [onPaymentSetup, emitResponse.responseTypes.SUCCESS]);
        
        return decodeEntities(settings.description || '');
    };

    /**
     * Label component for BOG payment method
     */
    const Label = function() {
        const elements = [label];
        
        if (settings.test_mode) {
            elements.push(
                createElement(
                    'span',
                    {
                        key: 'test-badge',
                        style: {
                            marginLeft: '8px',
                            padding: '2px 6px',
                            backgroundColor: '#ffcc00',
                            color: '#000',
                            fontSize: '11px',
                            borderRadius: '3px',
                            fontWeight: 'bold',
                            display: 'inline-block'
                        }
                    },
                    'TEST MODE'
                )
            );
        }
        
        return createElement(
            'span',
            { style: { width: '100%' } },
            elements
        );
    };

    /**
     * BOG payment method configuration
     */
    const BogPaymentMethod = {
        name: 'bog_payment',
        label: createElement(Label),
        content: createElement(Content),
        edit: createElement(Content),
        canMakePayment: function() { return true; },
        ariaLabel: label,
        supports: {
            features: settings.supports || [],
        },
    };

    // Register the payment method
    registerPaymentMethod(BogPaymentMethod);
})();