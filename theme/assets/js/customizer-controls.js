/**
 * Conditional controls in the Customizer.
 *
 * A PHP active_callback decides visibility once, when the panel is built —
 * so a control that depends on another setting stayed put until the page was
 * reloaded. This binds the same rules to the live setting values, which is
 * what someone changing a dropdown expects to see.
 */
( function ( api ) {
	'use strict';

	var deps = window.ocCustomizeDeps || {};

	api.bind( 'ready', function () {
		Object.keys( deps ).forEach( function ( controlId ) {
			// One rule, or several that must all hold.
			var rules = deps[ controlId ].all || [ deps[ controlId ] ];

			api.control( controlId, function ( control ) {
				var sync = function () {
					control.active.set( rules.every( function ( rule ) {
						var setting = api( rule.setting );

						return !! setting && rule.values.indexOf( String( setting.get() ) ) !== -1;
					} ) );
				};

				rules.forEach( function ( rule ) {
					api( rule.setting, function ( setting ) {
						setting.bind( sync );
						sync();
					} );
				} );
			} );
		} );
	} );
}( wp.customize ) );
