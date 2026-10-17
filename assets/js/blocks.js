( function () {
	'use strict';
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var __ = window.wp.i18n.__;
	var data = wcSettings.getSetting( 'ovajj_data', {} );
	var title = decode( data.title || __( 'Ovajj', 'ovajj-payment-gateway' ) );

	var Label = function () {
		return el(
			'span',
			{ className: 'ovajj-label' },
			data.logo ? el( 'img', { className: 'ovajj-label__logo', src: data.logo, alt: '' } ) : null,
			el( 'span', null, title )
		);
	};

	// Explains the currency conversion using the cart total supplied by Checkout Blocks (minor units).
	var conversionText = function ( props ) {
		var c = data.conversion;
		if ( ! c || ! c.enabled ) {
			return null;
		}
		var total = props && props.billing && props.billing.cartTotal ? props.billing.cartTotal.value : 0;
		var minor = props && props.billing && props.billing.currency ? props.billing.currency.minorUnit : 2;
		if ( ! c.rate || ! total ) {
			return __( 'Payments are processed in %1$s. Your %2$s total is converted to %1$s at the exchange rate when you pay.', 'ovajj-payment-gateway' )
				.replace( /%1\$s/g, c.wallet )
				.replace( /%2\$s/g, c.site );
		}
		var site = total / Math.pow( 10, minor );
		var wallet = ( site / c.rate ).toFixed( c.decimals );
		return __( 'Payments are processed in %1$s. Your total of %2$s %3$s will be charged as about %4$s %1$s (1 %1$s = %5$s %3$s).', 'ovajj-payment-gateway' )
			.replace( /%1\$s/g, c.wallet )
			.replace( /%2\$s/g, site.toFixed( minor ) )
			.replace( /%3\$s/g, c.site )
			.replace( /%4\$s/g, wallet )
			.replace( /%5\$s/g, String( Number( c.rate.toFixed( 6 ) ) ) );
	};

	var Content = function ( props ) {
		var conv = conversionText( props );
		return el(
			'div',
			{ className: 'ovajj-block-content' },
			data.is_sandbox
				? el( 'p', { className: 'ovajj-sandbox-badge' }, el( 'strong', null, __( 'SANDBOX MODE: no real money will be charged.', 'ovajj-payment-gateway' ) ) )
				: null,
			data.description ? el( 'p', null, decode( data.description ) ) : null,
			conv ? el( 'p', { className: 'ovajj-conversion-notice' }, conv ) : null,
			data.instructions ? el( 'p', null, decode( data.instructions ) ) : null
		);
	};

	registry.registerPaymentMethod( {
		name: 'ovajj',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
