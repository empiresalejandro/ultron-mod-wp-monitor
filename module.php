<?php
/**
 * Module Name: WordPress Monitor
 * Description: Monitor completo de la instalación de WordPress, servidor, configuración, seguridad y endpoints.
 * Version: 1.0.0
 *
 * @package Ultron
 * @subpackage WP_Monitor
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-monitor.php';

$ultron_wp_monitor = new Ultron_WP_Monitor();

add_filter( 'ultron_monitor_tabs', function( $tabs ) use ( $ultron_wp_monitor ) {
	$tabs['wordpress'] = [
		'label'    => __( 'WordPress', 'ultron' ),
		'callback' => [ $ultron_wp_monitor, 'render_tab' ],
	];
	return $tabs;
} );

add_action( 'ultron_dashboard_widgets', [ $ultron_wp_monitor, 'render_widget' ] );

$ultron_wp_monitor->maybe_create_table();
