<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Revisión del entorno, para comprobar un despliegue (Asistencia → Ajustes).
 * Cada punto dice si está bien y, si no, qué hacer.
 */
final class Diagnostico {
	public const BIEN    = 'bien';
	public const AVISO   = 'aviso';
	public const PROBLEMA = 'problema';

	/** @return array<int, array{estado: string, tema: string, detalle: string}> */
	public static function revisar(): array {
		$puntos = array();

		$puntos[] = self::punto(
			version_compare( PHP_VERSION, '8.2', '>=' ),
			'PHP ' . PHP_VERSION,
			'Hace falta PHP 8.2 o superior (hPanel → Avanzado → Configuración de PHP).'
		);

		$faltan = array_values( array_filter( array( 'openssl', 'mbstring', 'json' ), static fn( string $e ): bool => ! extension_loaded( $e ) ) );
		$puntos[] = self::punto( ! $faltan, 'Extensiones de PHP', 'Faltan: ' . implode( ', ', $faltan ) . '.' );

		$puntos[] = self::punto(
			self::libreriasCargadas(),
			'Librerías (vendor/)',
			'No están las librerías: instala el plugin desde el zip empaquetado o ejecuta "composer install --no-dev" en la carpeta del plugin.'
		);

		$puntos[] = self::punto(
			'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'localhost' === wp_parse_url( home_url(), PHP_URL_HOST ),
			'HTTPS',
			'Las passkeys exigen HTTPS. Activa el SSL del sitio y cambia la dirección a https:// (Ajustes → Generales).'
		);

		$puntos[] = self::punto(
			Secretos::vieneDeWpConfig(),
			'Secreto en wp-config.php',
			'El secreto está guardado en la base de datos. Muévelo a wp-config.php con define( \'PKA_SECRETO\', \'...\' );',
			self::AVISO
		);

		$puntos[] = self::punto(
			'' !== (string) get_option( 'permalink_structure' ),
			'Enlaces permanentes',
			'Elige "Nombre de la entrada" en Ajustes → Enlaces permanentes.',
			self::AVISO
		);

		$puntos[] = self::punto(
			'pka-vacio' === get_stylesheet(),
			'Tema vacío activo',
			'Activa el tema "Pekokis Asistencia (vacío)" en Apariencia → Temas.',
			self::AVISO
		);

		$cache    = array_values( array_filter( array( 'litespeed-cache/litespeed-cache.php', 'wp-super-cache/wp-cache.php', 'w3-total-cache/w3-total-cache.php', 'wp-rocket/wp-rocket.php' ), array( self::class, 'pluginActivo' ) ) );
		$puntos[] = self::punto( ! $cache, 'Sin plugins de caché', 'Desactiva: ' . implode( ', ', $cache ) . '. La caché puede mostrar el estado de otra persona.' );

		$puntos[] = self::punto(
			! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY && defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'Errores ocultos',
			'WP_DEBUG_DISPLAY está activo: los errores se muestran a cualquiera. Ponlo en false.'
		);

		$siguiente = wp_next_scheduled( Respaldo::EVENTO );
		$puntos[]  = self::punto(
			$siguiente && $siguiente > time() - 2 * HOUR_IN_SECONDS,
			'Cron',
			'Las tareas programadas no se están ejecutando (el respaldo mensual no saldría). Configura el cron real: php wp-cron.php cada 5 minutos.',
			self::AVISO
		);

		$puntos[] = self::punto(
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'Cron real',
			'Agrega define( \'DISABLE_WP_CRON\', true ); en wp-config.php y un cron en hPanel con "php wp-cron.php" cada 5 minutos.',
			self::AVISO
		);

		$puntos[] = self::punto(
			'' !== (string) Ajustes::todos()['correo_rrhh'],
			'Correo del respaldo',
			'El respaldo irá al correo del administrador. Configura el de RR.HH. en Asistencia → Exportar.',
			self::AVISO
		);

		$puntos[] = self::punto(
			(bool) Presencia::filasRedes( true ) || (bool) Pantallas::activas(),
			'Presencia configurada',
			'No hay redes del WiFi ni pantallas activas: nadie puede marcar.'
		);

		return $puntos;
	}

	/** Las librerías de Composer que el plugin necesita para funcionar. */
	public static function libreriasCargadas(): bool {
		return class_exists( \lbuchs\WebAuthn\WebAuthn::class )
			&& class_exists( \chillerlan\QRCode\QRCode::class )
			&& class_exists( \Shuchkin\SimpleXLSXGen::class );
	}

	/** is_plugin_active() solo existe en wp-admin; esta versión funciona en cualquier contexto. */
	public static function pluginActivo( string $plugin ): bool {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	}

	/** @return array{estado: string, tema: string, detalle: string} */
	private static function punto( bool $ok, string $tema, string $siNo, string $gravedad = self::PROBLEMA ): array {
		return array(
			'estado'  => $ok ? self::BIEN : $gravedad,
			'tema'    => $tema,
			'detalle' => $ok ? '' : $siNo,
		);
	}
}
