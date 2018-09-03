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

use EE\Model\Auth;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;

class Auth_Command extends EE_Command {

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var array $site_data Object containing essential site related information.
	 */
	private $site_data;

	public function __construct() {

		$this->fs = new Filesystem();
	}

	/**
	 * Creates global auth for admin tools .
	 */
	public function init() {

		$this->verify_htpasswd_is_present();
		$auth_data = [
			'site_url' => 'default',
			'username' => 'easyengine',
			'scope'    => 'admin-tools',
		];

		if ( ! empty( Auth::where( $auth_data ) ) ) {
			EE::log( 'Global auth exists on admin-tools' );

			return;
		}

		$site_auth_file_name = $auth_data['site_url'] . '_admin_tools';
		$pass                = EE\Utils\random_password();

		$auth_data['password'] = $pass;

		Auth::create( $auth_data );
		EE::exec( sprintf( 'docker exec %s htpasswd -bc /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_auth_file_name, $auth_data['username'], $auth_data['password'] ) );
		EE::success( sprintf( 'Global admin-tools auth added.' ) );

		EE::log( 'User: ' . $auth_data['username'] );
		EE::log( 'Pass: ' . $auth_data['password'] );
	}

	/**
	 * Creates http auth for a site.
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
	 * : Password for http auth.
	 */
	public function create( $args, $assoc_args ) {

		$this->verify_htpasswd_is_present();
		$global = $this->populate_info( $args, __FUNCTION__ );

		EE::debug( sprintf( 'ee auth start, Site: %s', $this->site_data->site_url ) );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		$site_url = $global ? 'default' : $this->site_data->site_url;

		if ( ! empty( Auth::where( [
			'site_url' => $site_url,
			'username' => $user,
		] ) ) ) {

			EE::error( "Auth with username $user already exists on $site_url" );
		}

		$site_auth_file_name = $site_url . '_admin_tools';
		$auth_data           = [
			'site_url' => $site_url,
			'username' => $user,
			'password' => $pass,
			'scope'    => 'site',
		];

		Auth::create( $auth_data );
		EE::exec( sprintf( 'docker exec %s htpasswd -bc /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_url, $user, $pass ) );

		$auth_data['scope'] = 'admin-tools';

		Auth::create( $auth_data );
		EE::exec( sprintf( 'docker exec %s htpasswd -bc /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_auth_file_name, $user, $pass ) );

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added/updated:', $this->site_data->site_url ) );
		EE::log( 'User: ' . $user );
		EE::log( 'Pass: ' . $pass );
	}

	/**
	 * Updates http auth for a site.
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
	 * : Password for http auth.
	 *
	 * [--site]
	 * : Update auth on site.
	 *
	 * [--admin-tools]
	 * : Update auth on admin-tools.
	 *
	 * [--all]
	 * : Update auth on both site and admin-tools.
	 */
	public function update( $args, $assoc_args ) {

		$this->verify_htpasswd_is_present();
		$scope  = $this->get_scope( $assoc_args );
		$global = $this->populate_info( $args, __FUNCTION__ );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		$site_url = $global ? 'default' : $this->site_data->site_url;
		$auths    = $this->get_auths( $site_url, $scope, $user );

		foreach ( $auths as $auth ) {
			$auth->update( [
				'password' => $pass,
			] );
			$site_auth_file_name = ( 'admin-tools' === $auth->scope ) ? $site_url . '_admin_tools' : $site_url;
			EE::exec( sprintf( 'docker exec %s htpasswd -b /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_auth_file_name, $user, $pass ) );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added/updated:', $this->site_data->site_url ) );
		EE::log( 'User: ' . $user );
		EE::log( 'Pass: ' . $pass );
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
	 *
	 * [--all]
	 * : Delete auth on both site and admin-tools.
	 *
	 * [--site]
	 * : Delete auth on site.
	 *
	 * [--admin-tools]
	 * : Delete auth for admin-tools.
	 */
	public function delete( $args, $assoc_args ) {

		$this->verify_htpasswd_is_present();
		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$user     = EE\Utils\get_flag_value( $assoc_args, 'user' );
		$scope    = $this->get_scope( $assoc_args );
		$auths    = $this->get_auths( $site_url, $scope, $user );

		foreach ( $auths as $auth ) {
			$username   = $auth->username;
			$User_scope = $auth->scope;
			$auth->delete();
			$site_auth_file_name = ( 'admin-tools' === $auth->scope ) ? $site_url . '_admin_tools' : $site_url;
			EE::exec( sprintf( 'docker exec %s htpasswd -D /etc/nginx/htpasswd/%s %s', EE_PROXY_TYPE, $site_auth_file_name, $auth->username ) );
			EE::success( sprintf( 'http auth successfully removed of user: %s for %s.', $username, $User_scope ) );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();
	}

	/**
	 * Lists http auth users of a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--all]
	 * : List auth on both site and admin-tools.
	 *
	 * [--site]
	 * : List auth on site.
	 *
	 * [--admin-tools]
	 * : List auth for admin-tools.
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
	 * ---
	 */
	public function list( $args, $assoc_args ) {

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$scope    = $this->get_scope( $assoc_args );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$auths    = $this->get_auths( $site_url, $scope, false );

		foreach ( $auths as $auth ) {
			$users[] = [
				'username' => $auth->username,
				'password' => $auth->password,
				'scope'    => $auth->scope,
			];
		}

		$formatter = new EE\Formatter( $assoc_args, [ 'username', 'password', 'scope' ] );
		$formatter->display_items( $users );
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

		// Note: If new sub-commands for whitelisting is added, function for it and this varibale needs to be updated.
		$commands = [ 'create', 'append', 'list', 'remove' ];
		if ( ! ( isset( $args[0] ) && in_array( $args[0], $commands ) ) ) {
			$help = PHP_EOL;
			foreach ( $commands as $command ) {
				$help .= "ee auth whitelist $command [<site-name>/global] [--ip=<ip>]" . PHP_EOL;
			}
			EE::error( 'Please use valid command syntax. You can use:' . $help );

		}

		$command = array_shift( $args );
		$global  = $this->populate_info( $args, __FUNCTION__ . ' ' . $command );

		$ip = EE\Utils\get_flag_value( $assoc_args, 'ip' );

		$file         = EE_CONF_ROOT . '/nginx/vhost.d/';
		$file         .= $global ? 'default_acl' : $this->site_data->site_url . '_acl';
		$user_ips     = array_filter( explode( ',', $ip ), 'strlen' );
		$existing_ips = $this->get_ips_from_file( $global );

		call_user_func_array( [ $this, "whitelist_$command" ], [ $file, $user_ips, $existing_ips ] );

		reload_global_nginx_proxy();
	}

	/**
	 * Function to create whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_create( $file, $user_ips, $existing_ips ) {

		$this->put_ips_to_file( $file, $user_ips );
		EE::success( sprintf( 'Created whitelist for `%s` scope with %s IP\'s.', $this->site_data->site_url, implode( ',', $user_ips ) ) );
	}

	/**
	 * Function to append to whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_append( $file, $user_ips, $existing_ips ) {

		$all_ips = array_unique( array_merge( $user_ips, $existing_ips ) );
		$this->put_ips_to_file( $file, $all_ips );
		EE::success( sprintf( 'Appended %s IP\'s to whitelist of `%s` scope', implode( ',', $user_ips ), $this->site_data->site_url ) );
	}

	/**
	 * Function to list whitelisted ips.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_list( $file, $user_ips, $existing_ips ) {

		if ( empty( $existing_ips ) ) {
			EE::error( sprintf( 'No Whitelisted IP\'s found for %s scope', $this->site_data->site_url ) );
		}

		EE::log( sprintf( 'Whitelisted IP\'s for %s scope', $this->site_data->site_url ) );
		foreach ( $existing_ips as $ips ) {
			EE::log( $ips );
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
			$removed_ips  = array_intersect( $existing_ips, $user_ips );
			$leftover_ips = array_diff( $user_ips, $removed_ips );
			$updated_ips  = array_diff( $existing_ips, $user_ips );
			$file_content = '';
			foreach ( $updated_ips as $individual_ip ) {
				$file_content .= "allow $individual_ip;" . PHP_EOL;
			}
			$this->fs->dumpFile( $file, $file_content );
		}
		if ( empty( $removed_ips ) ) {
			EE::error( sprintf( '%s IP\'s not found in whitelist of `%s` scope', implode( ',', $user_ips ), $this->site_data->site_url ) );
		}
		EE::warning( sprintf( 'Could not find %s IP\'s from whitelist of `%s` scope', implode( ',', $leftover_ips ), $this->site_data->site_url ) );
		EE::success( sprintf( 'Removed %s IP\'s from whitelist of `%s` scope', implode( ',', $removed_ips ), $this->site_data->site_url ) );
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
		$file         .= $global ? 'default_acl' : $this->site_data->site_url . '_acl';
		$existing_ips = [];
		if ( $this->fs->exists( $file ) ) {
			$existing_ips_in_file = array_slice( array_filter( explode( PHP_EOL, file_get_contents( $file ) ), 'trim' ), 1, - 1 );
			foreach ( $existing_ips_in_file as $ip_in_file ) {
				$existing_ips[] = str_replace( [ 'allow ', ';' ], '', trim( $ip_in_file ) );
			}
		}

		return $existing_ips;
	}

	/**
	 * Function to put list of ip's into a file.
	 *
	 * @param string $file Path of file to write ip's in.
	 * @param array $ips   List of ip's.
	 */
	private function put_ips_to_file( $file, $ips ) {

		$file_content = 'satisfy any;' . PHP_EOL;
		foreach ( $ips as $ip ) {
			$file_content .= "allow $ip;" . PHP_EOL;
		}
		$file_content .= 'deny all;';
		$this->fs->dumpFile( $file, $file_content );
	}

	/**
	 * Function to populate basic info from args
	 *
	 * @param array $args     args passed from function.
	 * @param string $command command name that is calling the function.
	 *
	 * @return bool $global Whether the command is global or site-specific.
	 */
	private function populate_info( $args, $command ) {

		$global = false;
		if ( isset( $args[0] ) && 'global' === $args[0] ) {
			$this->site_data = (object) [ 'site_url' => $args[0] ];
			$global          = true;
		} else {
			$args            = auto_site_name( $args, 'auth', $command );
			$this->site_data = get_site_info( $args, true, true, false );
		}

		return $global;
	}

	/**
	 * Check if htpasswd is present in the global-container.
	 */
	private function verify_htpasswd_is_present() {

		EE::debug( 'Verifying htpasswd is present.' );
		if ( EE::exec( sprintf( 'docker exec %s sh -c \'command -v htpasswd\'', EE_PROXY_TYPE ) ) ) {
			return;
		}
		EE::error( sprintf( 'Could not find apache2-utils installed in %s.', EE_PROXY_TYPE ) );
	}

	/**
	 * Get the appropriate scope from passed associative arguments.
	 *
	 * @param array $assoc_args Passed associative arguments.
	 *
	 * @return string Found scope.
	 */
	private function get_scope( $assoc_args ) {

		$scope_site        = $assoc_args['site'] ?? false;
		$scope_admin_tools = $assoc_args['admin-tools'] ?? false;

		if ( $scope_site && ! $scope_admin_tools ) {
			return 'site';
		}

		if ( $scope_admin_tools && ! $scope_site ) {
			return 'admin-tools';
		}

		return 'all';
	}

	/**
	 * Gets all the authentication objects from db.
	 *
	 * @param string $site_url Site URL.
	 * @param string $scope    The scope of auth.
	 * @param string $user     User for which the auth need to be fetched.
	 *
	 * @return array Array of auth models.
	 */
	private function get_auths( $site_url, $scope, $user ) {

		$where_conditions = [ 'site_url' => $site_url ];

		$user_error_msg = '';
		if ( $user ) {
			$where_conditions['username'] = $user;
			$user_error_msg               = ' with username: ' . $user;
		}

		if ( 'all' !== $scope ) {
			$where_conditions['scope'] = $scope;
		}

		$auths = Auth::where( $where_conditions );

		if ( empty( $auths ) ) {
			$all_error_msg  = ( 'all' === $scope ) ? '' : 'for ' . $scope;
			$site_error_msg = ( 'default' === $site_url ) ? 'global' : $site_url;
			EE::error( sprintf( 'Auth%s does not exists on %s %s', $user_error_msg, $site_error_msg, $all_error_msg ) );
		}

		return $auths;
	}
}
