(function () {
    'use strict';

    var registry = window.wc && window.wc.wcBlocksRegistry;
    var settingsApi = window.wc && window.wc.wcSettings;
    if (!registry || !settingsApi) {
        return;
    }

    var el = window.wp.element.createElement;
    var decode = window.wp.htmlEntities.decodeEntities;
    var settings = settingsApi.getSetting('ovajj_data', {});

    var title = decode(settings.title || 'Ovajj Payment Gateway');

    var Content = function () {
        return el('div', null, decode(settings.description || ''));
    };

    registry.registerPaymentMethod({
        name: 'ovajj',
        label: el('span', null, title),
        ariaLabel: title,
        content: el(Content, null),
        edit: el(Content, null),
        canMakePayment: function () { return true; },
        supports: { features: settings.supports || ['products'] }
    });
})();
