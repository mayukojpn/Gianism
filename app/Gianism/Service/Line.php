<?php
namespace Gianism\Service;
use Firebase\JWT\JWT;

/**
 * Line class
 *
 * @package gianism
 * @since 3.2.0
 */
class Line extends NoMailService {

	public $url_prefix = 'line-auth';

	public $verbose_service_name = 'LINE';

	public $umeta_id = '_wpg_line_id';

	public $umeta_profile_pic = '_wpg_line_pic';

	protected $pseudo_domain = 'pseudo.line.me';

	protected $option_keys = [
		'line_enabled'         => false,
		'line_channel_id'      => '',
		'line_channel_secret'  => '',
	];


	/**
	 * Constructor
	 *
	 * @param array $argument
	 */
	protected function __construct( array $argument = [] ) {
		parent::__construct( $argument );
		// Filter rewrite name
		add_filter( 'gianism_filter_service_prefix', function( $prefix ) {
			if ( 'line-auth' == $prefix ) {
				$prefix = 'line';
			}
			return $prefix;
		} );
	}

	/**
	 * Returns API endpoint
	 *
	 * @param string $action
	 *
	 * @return false|string
	 * @throws \Exception
	 */
	protected function get_api_url( $action ) {
		switch ( $action ) {
			case 'connect':
			case 'login':
				$auth_url = 'https://access.line.me/oauth2/v2.1/authorize';
				if ( function_exists( 'random_int' ) ) {
					$state = sha1( random_bytes(24) );
				} else {
					$state = sha1( uniqid() );
				}
				$this->session->write( 'line_state', $state );
				/**
				 * giansim_line_auth_params
				 *
				 * @param array  $args    Query parameters
				 * @param string $context login or connect.
				 */
				$args = apply_filters( 'giansim_line_auth_params', [
					'response_type' => 'code',
					'client_id' => $this->line_channel_id,
					'redirect_uri' => home_url( "/{$this->url_prefix}/" ),
					'scope' => rawurlencode( 'profile openid' ),
					'state' => $state,
				], $action );
				return add_query_arg( $args, $auth_url );
				break;
			default:
				return false;
				break;
		}
	}

	/**
	 * Check if user has line account.
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public function is_connected( $user_id ) {
		return (bool) get_user_meta( $user_id, $this->umeta_id, true );
	}


	/**
	 * @param int $user_id
	 *
	 * @return void
	 */
	public function disconnect( $user_id ) {
		delete_user_meta( $user_id, $this->umeta_id );
		delete_user_meta( $user_id, $this->umeta_profile_pic );
	}

	/**
	 * Parse request.
	 *
	 * @return \stdClass
	 * @throws \Exception
	 */
	protected function validate_callback() {
		$code  = $this->input->request( 'code' );
		$state = $this->input->request( 'state' );
		$saved = $this->session->get(  'line_state' );
		$error = $this->input->request( 'error' );
		$msg   = $this->input->request( 'error_description' );
		if ( $error || $msg ) {
			throw new \Exception( $msg, 500 );
		}
		if ( $state !== $saved ) {
			throw new \Exception( __( 'Sorry, but wrong access. Please try again.', 'wpg-gianism' ), 500 );
		}
		$data = [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => home_url( "/{$this->url_prefix}/" ),
			'client_id' => $this->line_channel_id,
			'client_secret' => $this->line_channel_secret,
		];
		$result = wp_remote_post( 'https://api.line.me/oauth2/v2.1/token', [
			'body' => $data,
		] );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message(), 500 );
		}
		$json = json_decode( $result['body'] );
		if ( ! $json || ! isset( $json->id_token ) ) {
			throw new \Exception( __( 'Sorry, but failed to parse request.', 'wp-gianism' ), 500 );
		}
		$jwt = JWT::decode( $json->id_token, $this->line_channel_secret, ['HS256'] );
		$json->id_token = $jwt;
		return $json;
	}

	/**
	 * Handle actions.
	 *
	 * @param string $action
	 */
	public function handle_default( $action ) {
		$redirect_url = $this->session->get( 'redirect_to' );
		switch ( $action ) {
			case 'login':
				try {
					$response = $this->validate_callback();
					$line_id  = $response->id_token->sub;
					$user_id  = $this->get_meta_owner( $this->umeta_id, $line_id );
					if ( ! $user_id ) {
						$this->test_user_can_register();
						$email = $this->create_pseudo_email( $line_id );
						if ( email_exists( $email ) ) {
							throw new \Exception( $this->duplicate_account_string() );
						}
						$user_name = 'line-' . $line_id;
						/**
						 * @see Facebook
						 */
						$user_name = apply_filters( 'gianism_register_name', $user_name, $this->service, $response );
						$user_id = wp_create_user( $user_name, wp_generate_password(), $email );
						if ( is_wp_error( $user_id ) ) {
							throw new \Exception( $this->registration_error_string() );
						}
						// Update user meta
						update_user_meta( $user_id, $this->umeta_id, $line_id );
						update_user_meta( $user_id, $this->umeta_profile_pic, $response->id_token->picture );
						$profile_name = $response->id_token->name;
						update_user_meta( $user_id, 'nickname', $profile_name );
						$this->db->update(
							$this->db->users,
							array(
								'display_name' => $profile_name,
							),
							array(
								'ID' => $user_id,
							),
							array( '%s' ),
							array( '%d' )
						);
						$this->user_password_unknown( $user_id );
						$this->hook_connect( $user_id, $response, true );
						$this->welcome( $profile_name );
					}
					// Make user logged in
					wp_set_auth_cookie( $user_id, true );
					$redirect_url = $this->filter_redirect( $redirect_url, 'login' );
				} catch ( \Exception $e ) {
					$this->auth_fail( $e->getMessage() );
					$redirect_url = wp_login_url( $redirect_url, true );
					$redirect_url = $this->filter_redirect( $redirect_url, 'login-failure' );
				}
				wp_redirect( $redirect_url );
				exit;
				break;
			case 'connect':
				try {
					$response = $this->validate_callback();
					$line_id = $response->id_token->sub;
					if ( $this->get_meta_owner( $this->umeta_id, $line_id ) ) {
						throw new \Exception( $this->duplicate_account_string() );
					}
					update_user_meta( get_current_user_id(), $this->umeta_id, $line_id );
					// Fires hook
					$this->hook_connect( get_current_user_id(), $response );
					// Save message
					$this->welcome( $response->id_token->name );
					// Apply filter
					$redirect_url = $this->filter_redirect( $redirect_url, 'connect' );
				} catch ( \Exception $e ) {
					$this->auth_fail( $e->getMessage() );
					$redirect_url = $this->filter_redirect( $redirect_url, 'connect-failure' );
				}
				// Connection finished. Let's redirect.
				if ( ! $redirect_url ) {
					$redirect_url = admin_url( 'profile.php' );
				}
				wp_redirect( $redirect_url );
				exit;
				break;
			default:
				/**
				 * @see Facebook
				 */
				do_action( 'gianism_extra_action', $this->service_name, $action, [
					'redirect_to' => $redirect_url,
				] );
				$this->input->wp_die( sprintf( __( 'Sorry, but wrong access. Please go back to <a href="%s">%s</a>.', 'wp-gianism' ), home_url( '/' ), get_bloginfo( 'name' ) ), 500, false );
				break;
		}
	}


}
