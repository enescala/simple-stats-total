<?php
/**
 * Simple Stats Total
 *
 * Plugin Name: Simple Stats Total
 * Plugin URI:  https://www.themefiber.com/simple-stats-total
 * Description: Collect and display total summary for visited pages, referer urls, ip address, browsers and operating systems
 * Version:     1.0.0
 * Author:      themefiber
 * Author URI:  https://www.themefiber.com/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: simple-stats-total
 * Domain Path: /languages
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License version 2, as published by the Free Software Foundation. You may NOT assume
 * that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once 'vendor/autoload.php';

use \UAParser\Parser;

if ( ! class_exists( 'SimpleStatsTotal' ) ) :
class SimpleStatsTotal {
	private static $instance;

	private $plugin_db_version = '1.0.0';
	private $db_table = 'simple_stats_total';
	private $textdomain = 'simple-stats-total';

	private $pages;
	private $referers;
	private $iplist;
	private $browsers;
	private $oses;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->install_db();
		$this->init_actions();
	}

	public function init_actions() {
		add_action( 'admin_menu', array( $this, 'admin_menu_item' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'add_purge_stats_script' ) );

		// Exclude admin pages in stats
		if ( ! is_admin() ) {
			add_action( 'wp_head', array( $this, 'update_stats' ) );
		}
		if ( wp_doing_ajax() ) {
			add_action( 'wp_ajax_purge_stats', array( $this, 'ajax_handler' ) );
		}
	}

	public function install_db() {
		global $wpdb;

		if ( get_option( 'sst_plugin_db_version' ) === $this->plugin_db_version ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . $this->db_table;

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			date datetime NOT NULL,
			page_url text NOT NULL,
			referer_url text NOT NULL,
			user_agent varchar(255) NOT NULL,
			ip_address varchar(100) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( $sql );

		add_option( 'sst_plugin_db_version', $this->plugin_db_version );
	}

	public function enqueue_assets() {
		$screen = get_current_screen();

		if ( ! is_object( $screen ) ) {
			$screen = new \stdClass();
		}
		if ( ! property_exists( $screen, 'id' ) ) {
			return;
		}
		if ( $screen->id === 'tools_page_simple-stats-total' ) {
			wp_enqueue_style( 'bootstrap-css', plugin_dir_url( __FILE__ ) . 'assets/css/bootstrap.min.css' );
			wp_enqueue_style( 'simple-stats-total-css', plugin_dir_url( __FILE__ ) . 'assets/css/main.css' );
			wp_enqueue_script( 'bootstrap-js', plugin_dir_url( __FILE__ ) . 'assets/js/bootstrap.min.js' );
		}
	}

	public function admin_menu_item() {
		add_management_page(
			__( 'Simple Stats Total', $this->textdomain ),
			__( 'Simple Stats Total', $this->textdomain ),
			'manage_options',
			$this->textdomain,
			array( $this, 'stats_page' )
		);
	}

	public function get_stats() {
		global $wpdb;
		$results = array();
		$table_name = $wpdb->prefix . $this->db_table;
		$results = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC", 'ARRAY_A' );
		return $results;
	}

	public function update_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . $this->db_table;

		$page_url    = $this->get_page_url();
		$referer_url = $this->get_referer();
		$user_agent  = $this->get_user_agent();
		$ip_address  = $this->get_ip_address();

		$wpdb->insert(
			$table_name,
			array(
				'date'        => current_time( 'mysql', 1 ),
				'page_url'    => $page_url,
				'referer_url' => $referer_url,
				'user_agent'  => $user_agent,
				'ip_address'  => $ip_address
			)
		);
	}

	public function purge_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . $this->db_table;
		$wpdb->query( "TRUNCATE TABLE $table_name" );
	}

	public function add_purge_stats_script() {
	?>
		<script>
			function onSubmit(){
				var nonce = "<?php echo wp_create_nonce( 'purge_stats' ); ?>";
				var data = 'nonce=' + nonce + '&action=purge_stats';
				var request = new XMLHttpRequest();
				request.open('POST', ajaxurl, true);
				request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				request.onload = function(){
					if (request.status >= 200 && request.status < 400 && request.responseText != -1 ){
						try {
							var data = JSON.parse(request.responseText);
							var btnPurge = document.getElementById('plugin-description');
							var message = document.createElement('div');
							if (data.success) {
								message.className = 'notice notice-success is-dismissible';
							} else {
								message.className = 'notice notice-error is-dismissible';
							}
							message.innerHTML = '<p>' + data.message + '</p>';
							btnPurge.parentNode.insertBefore(message, btnPurge.nextSibling);
						} catch(e) {}
					}
				};
				request.send(data);
			}
			window.onload = function(){
				document.getElementById('btn-purge').onclick = function(){
					onSubmit();
				};
			}
		</script>
	<?php
	}

	private function get_page_url() {
		$current_url = get_page_link();
		return empty( $current_url ) ? '' : $current_url;
	}

	private function get_user_agent() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || strpos( $_SERVER['HTTP_USER_AGENT'], 'WordPress' ) !== false ) {
			return '';
		}
		return substr( $_SERVER['HTTP_USER_AGENT'], 0, 255 );
	}

	private function get_ip_address() {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		return preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] );
	}

	private function get_referer() {
		$referer = wp_get_referer();
		if ( empty( $referer ) ) {
			$referer = '';
		}
		return $referer;
	}

	private function calculate_stats() {
		$pages
		= $referers
		= $iplist
		= $browsers
		= $oses = [];

		$results = $this->get_stats();

		$parser = Parser::create();

		foreach ( $results as $result ) {
			$pages[] = $result['page_url'];
			$referers[] = $result['referer_url'];
			$iplist[] = $result['ip_address'];
			$ua = $parser->parse( $result['user_agent'] );
			$browsers[] = $ua->ua->toString();
			$oses[] = $ua->os->toString();
		}

		$this->pages = array_count_values( $pages );
		$this->referers = array_count_values( $referers );
		$this->iplist = array_count_values( $iplist );
		$this->browsers = array_count_values( $browsers );
		$this->oses = array_count_values( $oses );

		$this->sort_stats();
	}

	private function sort_stats() {
		arsort( $this->pages );
		arsort( $this->referers );
		arsort( $this->iplist );
		arsort( $this->browsers );
		arsort( $this->oses );
	}

	public function stats_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->enqueue_assets();

		$this->calculate_stats();
		?>
		<div class="wrap">
			<div class="container-fluid">
				<div class="row">
					<div class="col-12">
						<h1><?php echo get_admin_page_title(); ?></h1>
						<p id="plugin-description"><?php echo __( 'Pages visit statistics', $this->textdomain ); ?></p>
						<button type="button" id="btn-purge" class="btn btn-danger float-right"><?php echo __( 'Purge All Stats', $this->textdomain); ?></button>
						<div class="clearfix"></div>
						<br />
					</div>
				</div>
				<div class="row">
					<div class="col-2">
						<div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
							<a class="nav-link active" id="v-pills-page-tab" data-toggle="pill" href="#v-pills-page" role="tab" aria-controls="v-pills-page" aria-selected="true"><?php echo __( 'Page', $this->textdomain); ?></a>
							<a class="nav-link" id="v-pills-referer-tab" data-toggle="pill" href="#v-pills-referer" role="tab" aria-controls="v-pills-referer" aria-selected="false"><?php echo __( 'Referer', $this->textdomain); ?></a>
							<a class="nav-link" id="v-pills-ip-address-tab" data-toggle="pill" href="#v-pills-ip-address" role="tab" aria-controls="v-pills-ip-address" aria-selected="false"><?php echo __( 'IP Address', $this->textdomain); ?></a>
							<a class="nav-link" id="v-pills-browser-tab" data-toggle="pill" href="#v-pills-browser" role="tab" aria-controls="v-pills-browser" aria-selected="false"><?php echo __( 'Browser', $this->textdomain); ?></a>
							<a class="nav-link" id="v-pills-os-tab" data-toggle="pill" href="#v-pills-os" role="tab" aria-controls="v-pills-os" aria-selected="false">OS</a>
						</div>
					</div>
					<div class="col-10">
						<div class="tab-content" id="v-pills-tabContent">
							<div class="tab-pane fade show active" id="v-pills-page" role="tabpanel" aria-labelledby="v-pills-page-tab">
								<div class="table-responsive">
									<table class="table table-sm table-hover">
										<thead>
											<tr>
												<th><?php echo __( 'Page URL', $this->textdomain ); ?></th>
												<th><?php echo __( 'Counter', $this->textdomain ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $this->pages as $url => $count ) { ?>
												<tr>
													<td><?php echo $url; ?></td>
													<td><?php echo $count; ?></td>
												</tr>
											<?php } ?>
										</tbody>
									</table>
								</div>
							</div>
							<div class="tab-pane fade" id="v-pills-referer" role="tabpanel" aria-labelledby="v-pills-referer-tab">
								<div class="table-responsive">
									<table class="table table-sm table-hover">
										<thead>
											<tr>
												<th><?php echo __( 'Referer URL', $this->textdomain ); ?></th>
												<th><?php echo __( 'Counter', $this->textdomain); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $this->referers as $url => $count ) { ?>
												<tr>
													<td><?php echo $url; ?></td>
													<td><?php echo $count; ?></td>
												</tr>
											<?php } ?>
										</tbody>
									</table>
								</div>
							</div>
							<div class="tab-pane fade" id="v-pills-ip-address" role="tabpanel" aria-labelledby="v-pills-ip-address-tab">
								<div class="table-responsive">
									<table class="table table-sm table-hover">
										<thead>
											<tr>
												<th><?php echo __( 'Visitor IP Address', $this->textdomain); ?></th>
												<th><?php echo __( 'Counter', $this->textdomain); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $this->iplist as $ip => $count ) { ?>
												<tr>
													<td><?php echo $ip; ?></td>
													<td><?php echo $count; ?></td>
												</tr>
											<?php } ?>
										</tbody>
									</table>
								</div>
							</div>
							<div class="tab-pane fade" id="v-pills-browser" role="tabpanel" aria-labelledby="v-pills-browser-tab">
								<div class="table-responsive">
									<table class="table table-sm table-hover">
										<thead>
											<tr>
												<th><?php echo __( 'Web Browser', $this->textdomain); ?></th>
												<th><?php echo __( 'Counter', $this->textdomain); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $this->browsers as $ua => $count ) { ?>
												<tr>
													<td><?php echo $ua; ?></td>
													<td><?php echo $count; ?></td>
												</tr>
											<?php } ?>
										</tbody>
									</table>
								</div>
							</div>
							<div class="tab-pane fade" id="v-pills-os" role="tabpanel" aria-labelledby="v-pills-os-tab">
								<div class="table-responsive">
									<table class="table table-sm table-hover">
										<thead>
											<tr>
												<th><?php echo __( 'Operating System', $this->textdomain); ?></th>
												<th><?php echo __( 'Counter', $this->textdomain); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $this->oses as $os => $count ) { ?>
												<tr>
													<td><?php echo $os; ?></td>
													<td><?php echo $count; ?></td>
												</tr>
											<?php } ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_handler() {
		$response = array(
			'success' => false,
			'nonce' => wp_create_nonce( 'purge_stats' ),
		);

		check_ajax_referer( 'purge_stats', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			$response['message'] = 'You do not have the required capability to do that.';
			wp_send_json( $response );
		}

		if ( empty( $_POST['action'] ) || empty( $_POST['nonce'] ) ) {
			$response['message'] = 'Invalid request.';
			wp_send_json( $response );
		}

		$this->purge_stats();

		$response['success'] = true;
		$response['message'] = 'The page visits statistics data successfully erased.';

		wp_send_json( $response );
	}
}

$simple_stats_total = SimpleStatsTotal::get_instance();

endif;
