<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

delete_option( 'csfr_username' );
delete_option( 'csfr_password' );
delete_option( 'csfr_feed_id' );
delete_option( 'csfr_post_status' );
delete_option( 'csfr_post_as' );
delete_option( 'csfr_post_category' );
delete_option( 'csfr_cron_start' );
delete_option( 'csfr_cron_freq' );
delete_option( 'csfr_cron_enabled' );
