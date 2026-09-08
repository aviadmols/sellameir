<?php
/**
 * ONE-TIME bootstrap: create administrator maor@sellameir.com
 * Safe to load once; no password is stored in code.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function () {
		if ( get_option( 'sella_maor_bootstrap_done' ) ) {
			return;
		}

		if ( username_exists( 'maor' ) || email_exists( 'maor@sellameir.com' ) ) {
			update_option( 'sella_maor_bootstrap_done', 1, false );
			return;
		}

		$password = wp_generate_password( 20, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => 'maor',
				'user_email'   => 'maor@sellameir.com',
				'user_pass'    => $password,
				'display_name' => 'Maor',
				'nickname'     => 'Maor',
				'role'         => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			update_option( 'sella_maor_bootstrap_error', $user_id->get_error_message(), false );
			update_option( 'sella_maor_bootstrap_done', 1, false );
			return;
		}

		update_option( 'sella_maor_bootstrap_pass', $password, false );
		update_option( 'sella_maor_bootstrap_done', 1, false );
	},
	5
);
