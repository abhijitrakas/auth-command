<?php

/**
 * Adds HTTP auth to a site.
 *
 * ## EXAMPLES
 *
 *        # Add auth to a site
 *        $ ee auth create example.com --user=test --pass=test
 *
 *        # Delete auth from a site
 *        $ ee auth delete example.com --user=test
 *
 * @package ee-cli
 */

use \Symfony\Component\Filesystem\Filesystem;

class Auth_Command extends EE_Command {

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var array $db Object containing essential site related information.
	 */
	private $db;

	public function __construct() {

		$this->fs = new Filesystem();
	}


	/**
	 * Creates/Updates http auth for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be secured.
	 *
	 * [--user=<user>]
	 * : Username for http auth.
	 *
	 * [--pass=<pass>]
	 * : Password for http auth
	 *
	 * @alias update
	 */
	public function create( $args, $assoc_args ) {

		$args     = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ );
		$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->db || ! $this->db->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		EE::debug( sprintf( 'ee auth start, Site: %s', $this->db->site_url ) );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		EE::debug( 'Verifying htpasswd is present.' );
		$check_htpasswd_present = EE::exec( sprintf( 'docker exec %s sh -c \'command -v htpasswd\'', EE_PROXY_TYPE ) );
		if ( ! $check_htpasswd_present ) {
			EE::error( sprintf( 'Could not find apache2-utils installed in %s.', EE_PROXY_TYPE ) );
		}
		$params = $this->fs->exists( EE_CONF_ROOT . '/nginx/htpasswd/' . $this->db->site_url ) ? 'b' : 'bc';
		EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $params, $this->db->site_url, $user, $pass ) );

		EE::log( 'Reloading global reverse proxy.' );
		$this->reload();

		EE::log( sprintf( 'Auth successfully updated for %s scope. New values added/updated:', $this->db->site_url ) );
		EE::log( 'User:' . $user );
		EE::log( 'Pass:' . $pass );
	}

	/**
	 * Deletes http auth for a site. Default: removes http auth from site. If `--user` is passed it removes that
	 * specific user.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--user=<user>]
	 * : Username that needs to be deleted.
	 */
	public function delete( $args, $assoc_args ) {

		$args     = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ );
		$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->db || ! $this->db->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}
		$user = EE\Utils\get_flag_value( $assoc_args, 'user' );

		if ( $user ) {
			EE::exec( sprintf( 'docker exec %s htpasswd -D /etc/nginx/htpasswd/%s %s', EE_PROXY_TYPE, $this->db->site_url, $user ), true, true );
		} else {
			$this->fs->remove( EE_CONF_ROOT . '/nginx/htpasswd/' . $this->db->site_url );
			EE::log( sprintf( 'http auth removed for %s', $this->db->site_url ) );
		}
		$this->reload();
	}

	/**
	 * Lists http auth users of a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 *   - count
	 *   - text
	 * ---
	 */
	public function list( $args, $assoc_args ) {

		$args     = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ );
		$format   = EE\Utils\get_flag_value( $assoc_args, 'format' );
		$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		$file     = EE_CONF_ROOT . '/nginx/htpasswd/' . $this->db->site_url;
		if ( $this->fs->exists( $file ) ) {
			$user_lines = explode( PHP_EOL, trim( file_get_contents( $file ) ) );
			foreach ( $user_lines as $line ) {
				$users[]['users'] = strstr( $line, ':', true );
			}

			if ( 'text' === $format ) {
				foreach ( $users as $user ) {
					EE::log( $user['users'] );
				}
			} else {
				$formatter = new EE\Formatter( $assoc_args, [ 'users' ] );
				$formatter->display_items( $users );
			}
		} else {
			EE::error( sprintf( 'http auth not enabled on %s', $this->db->site_url ) );
		}
	}

	/**
	 * create, append, remove, list ip whitelisting for a site or globally.
	 *
	 * ## OPTIONS
	 *
	 * [<create>]
	 * : Create ip whitelisting for a site or globally.
	 *
	 * [<append>]
	 * : Append ips in whitelisting of a site or globally.
	 *
	 * [<list>]
	 * : List whitelisted ip's of a site or of global scope.
	 *
	 * [<remove>]
	 * : Remove whitelisted ip's of a site or of global scope.
	 *
	 * [<site-name>]
	 * : Name of website to be secured / `global` for global scope.
	 *
	 * [--ip=<ip>]
	 * : Comma seperated ips.
	 */
	public function whitelist( $args, $assoc_args ) {

		$commands = [ 'create', 'append', 'list', 'remove' ];
		if ( ! ( isset( $args[0] ) && in_array( $args[0], $commands ) ) ) {
			$help = PHP_EOL;
			foreach ( $commands as $command ) {
				$help .= "ee auth whitelist $command [<site-name>/global] [--ip=<ip>]" . PHP_EOL;
			}
			EE::error( 'Please use valid command syntax. You can use:' . $help );

		}

		$command = array_shift( $args );
		$global  = false;
		if ( isset( $args[0] ) && 'global' === $args[0] ) {
			$this->db = (object) [ 'site_url' => $args[0] ];
			$global   = true;
		} else {
			$args     = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ . ' ' . $command );
			$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		}

		$ip = EE\Utils\get_flag_value( $assoc_args, 'ip' );

		$file         = EE_CONF_ROOT . '/nginx/vhost.d/';
		$file         .= $global ? 'default_acl' : $this->db->site_url . '_acl';
		$user_ips     = array_filter( explode( ',', $ip ), 'strlen' );
		$existing_ips = $this->get_ips_from_file( $global );

		call_user_func_array( [ $this, "whitelist_$command" ], [ $file, $user_ips, $existing_ips ] );

		$this->reload();
	}

	/**
	 * Function to create whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_create( $file, $user_ips, $existing_ips ) {

		foreach ( $user_ips as $ip ) {
			$file_content = "allow $ip;" . PHP_EOL;
		}
		$this->fs->dumpFile( $file, $file_content );
	}

	/**
	 * Function to append to whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_append( $file, $user_ips, $existing_ips ) {

		$all_ips      = array_unique( array_merge( $user_ips, $existing_ips ) );
		$file_content = '';
		foreach ( $all_ips as $individual_ip ) {
			$file_content .= "allow $individual_ip;" . PHP_EOL;
		}
		$this->fs->dumpFile( $file, $file_content );
	}

	/**
	 * Function to list whitelisted ips.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_list( $file, $user_ips, $existing_ips ) {

		if ( ! empty( $existing_ips ) ) {
			EE::log( 'Whitelisted IPs for %s scope', $this->db->site_url );
			foreach ( $existing_ips as $ips ) {
				EE::log( $ips );
			}
		}
	}

	/**
	 * Function to remove whitelisted ips.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_remove( $file, $user_ips, $existing_ips ) {

		if ( empty( $user_ips ) || 'all' === $user_ips[0] ) {
			$this->fs->remove( $file );
		} else {
			$updated_ips  = array_diff( $existing_ips, $user_ips );
			$file_content = '';
			foreach ( $updated_ips as $individual_ip ) {
				$file_content .= "allow $individual_ip;" . PHP_EOL;
			}
			$this->fs->dumpFile( $file, $file_content );
		}
	}


	/**
	 * Function to reload the global reverse proxy to update the effect of changes done.
	 */
	private function reload() {

		EE::exec( sprintf( 'docker exec %s sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -s reload"', EE_PROXY_TYPE ) );
	}

	/**
	 * Function to get the list of ip's from given file.
	 *
	 * @param boolean $global Is the scope global or site specific.
	 *
	 * @return array of existing ips.
	 */
	private function get_ips_from_file( $global ) {

		$file         = EE_CONF_ROOT . '/nginx/vhost.d/';
		$file         .= $global ? 'default_acl' : $this->db->site_url . '_acl';
		$existing_ips = [];
		if ( $this->fs->exists( $file ) ) {
			$existing_ips_in_file = array_filter( explode( PHP_EOL, trim( file_get_contents( $file ) ) ), 'strlen' );
			foreach ( $existing_ips_in_file as $ip_in_file ) {
				$existing_ips[] = str_replace( [ 'allow ', ';' ], '', trim( $ip_in_file ) );
			}
		}

		return $existing_ips;
	}

}
