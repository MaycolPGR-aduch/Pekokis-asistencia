<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Cierra lo que WordPress trae abierto y este sistema no usa (plan técnico, sección 9).
 */
final class Endurecimiento {
	public static function registrar(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		// El sistema es interno: nunca debe aparecer en buscadores.
		add_filter( 'pre_option_blog_public', static fn() => '0' );

		add_filter( 'rest_endpoints', array( self::class, 'ocultarUsuarios' ) );

		// El subdominio va sin el firewall de Cloudflare: limitar los intentos de acceso a wp-admin.
		add_action( 'wp_login_failed', array( self::class, 'sumarFalloLogin' ) );
		add_filter( 'authenticate', array( self::class, 'bloquearLogin' ), 30, 1 );
	}

	public const MAX_FALLOS_LOGIN = 5;

	public static function sumarFalloLogin(): void {
		Temporales::sumarFallo( 'login|' . Peticion::ip(), 15 * MINUTE_IN_SECONDS );
	}

	/** Después de 5 intentos fallidos desde una IP, bloquea el acceso 15 minutos. */
	public static function bloquearLogin( mixed $usuario ): mixed {
		if ( Temporales::bloqueado( 'login|' . Peticion::ip(), self::MAX_FALLOS_LOGIN ) ) {
			return new \WP_Error( 'pka_login_bloqueado', '<strong>Demasiados intentos fallidos.</strong> Espera 15 minutos y vuelve a intentarlo.' );
		}

		return $usuario;
	}

	/**
	 * Quita el listado de usuarios de la API para quien no puede verlos,
	 * para que no se puedan enumerar las cuentas de los administradores.
	 *
	 * @param array<string, mixed> $rutas
	 * @return array<string, mixed>
	 */
	public static function ocultarUsuarios( array $rutas ): array {
		if ( current_user_can( 'list_users' ) ) {
			return $rutas;
		}

		foreach ( array_keys( $rutas ) as $ruta ) {
			if ( str_starts_with( $ruta, '/wp/v2/users' ) ) {
				unset( $rutas[ $ruta ] );
			}
		}

		return $rutas;
	}
}
