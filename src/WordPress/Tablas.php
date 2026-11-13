<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Nombres de las tablas propias del plugin (prefijo de WordPress + "pka_").
 */
final class Tablas {
	public static function nombre( string $tabla ): string {
		global $wpdb;

		return $wpdb->prefix . 'pka_' . $tabla;
	}
}
