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
			var dep = deps[ controlId ];

			api.control( controlId, function ( control ) {
				api( dep.setting, function ( setting ) {
					var sync = function () {
						control.active.set( dep.values.indexOf( String( setting.get() ) ) !== -1 );
					};

					setting.bind( sync );
					sync();
				} );
			} );
		} );
	} );
}( wp.customize ) );
