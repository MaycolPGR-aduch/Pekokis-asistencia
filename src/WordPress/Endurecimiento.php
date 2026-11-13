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
