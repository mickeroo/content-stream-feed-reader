<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

delete_option( CSFR_OPTIONS );
