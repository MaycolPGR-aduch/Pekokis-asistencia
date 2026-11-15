<?php
/**
 * Plugin Name: Pekokis Asistencia
 * Description: Registro de asistencia del personal del taller: marca desde el celular con passkey y presencia por WiFi del taller o QR.
 * Version: 0.12.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Pekokis
 * Text Domain: pekokis-asistencia
 */

defined( 'ABSPATH' ) || exit;

define( 'PKA_VERSION', '0.12.0' );
define( 'PKA_FILE', __FILE__ );
define( 'PKA_DIR', plugin_dir_path( __FILE__ ) );
define( 'PKA_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( PKA_DIR . 'vendor/autoload.php' ) ) {
	require_once PKA_DIR . 'vendor/autoload.php';
} else {
	// Sin Composer el plugin sigue cargando sus propias clases (PSR-4 sobre src/).
	spl_autoload_register(
		static function ( string $clase ): void {
			$prefijo = 'Pekokis\\Asistencia\\';

			if ( 0 !== strpos( $clase, $prefijo ) ) {
				return;
			}

			$archivo = PKA_DIR . 'src/' . str_replace( '\\', '/', substr( $clase, strlen( $prefijo ) ) ) . '.php';

			if ( is_readable( $archivo ) ) {
				require_once $archivo;
			}
		}
	);
}

// WordPress exige un tema activo, pero todas las pantallas las dibuja el plugin.
// El tema vacío vive aquí para que el sistema se despliegue como una sola pieza.
register_theme_directory( PKA_DIR . 'temas' );

register_activation_hook( PKA_FILE, array( \Pekokis\Asistencia\WordPress\Instalador::class, 'activar' ) );
register_deactivation_hook( PKA_FILE, array( \Pekokis\Asistencia\WordPress\Respaldo::class, 'desprogramar' ) );
register_deactivation_hook( PKA_FILE, array( \Pekokis\Asistencia\WordPress\Notificaciones::class, 'desprogramar' ) );

add_action( 'plugins_loaded', array( \Pekokis\Asistencia\WordPress\Plugin::class, 'iniciar' ) );
