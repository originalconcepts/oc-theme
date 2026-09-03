<?php
/**
 * Test bootstrap: no WordPress, no WooCommerce. Only code that is pure —
 * a function of its arguments — is tested here, and it must load without
 * either.
 */

declare( strict_types = 1 );

define( 'OC_TESTS', true );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../theme/inc/shipping/class-oc-shipping-quote.php';
require_once __DIR__ . '/../theme/inc/shipping/class-oc-shipping-rules.php';
require_once __DIR__ . '/../theme/inc/marketing/class-oc-marketing-settings.php';
require_once __DIR__ . '/../theme/inc/marketing/class-oc-marketing-payload.php';
