<?php

namespace Pekokis\Asistencia\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Mensajes de una sola vez para el usuario del panel (después de redirigir).
 */
final class Avisos {
	public static function agregar( string $tipo, string $html ): void {
		$clave   = self::clave();
		$avisos  = get_transient( $clave );
		$avisos  = is_array( $avisos ) ? $avisos : array();
		$avisos[] = array( 'tipo' => $tipo, 'html' => $html );

		set_transient( $clave, $avisos, 5 * MINUTE_IN_SECONDS );
	}

	public static function mostrar(): void {
		$clave  = self::clave();
		$avisos = get_transient( $clave );

		if ( ! is_array( $avisos ) ) {
			return;
		}

		delete_transient( $clave );

		foreach ( $avisos as $aviso ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $aviso['tipo'] ),
				wp_kses( $aviso['html'], array( 'strong' => array(), 'code' => array(), 'br' => array(), 'input' => array( 'type' => array(), 'readonly' => array(), 'value' => array(), 'class' => array() ) ) )
			);
		}
	}

	private static function clave(): string {
		return 'pka_avisos_' . get_current_user_id();
	}
}
