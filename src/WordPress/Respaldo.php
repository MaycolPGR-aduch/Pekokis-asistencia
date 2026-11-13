<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Respaldo fuera de Hostinger: el registro se guarda 5 años y los backups del
 * hosting duran días. Cada mes se envía por correo el Excel del mes anterior.
 *
 * Usa el cron de WordPress, que en Hostinger debe ejecutarse con el cron real
 * (php wp-cron.php), igual que en producción.
 */
final class Respaldo {
	public const EVENTO = 'pka_diario';

	private const OPCION_ULTIMO = 'pka_respaldo_ultimo';

	public static function registrar(): void {
		add_action( self::EVENTO, array( self::class, 'diario' ) );

		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			// Todos los días a las 06:00 de Lima.
			$manana = new DateTimeImmutable( 'tomorrow 06:00', Ajustes::zona() );
			wp_schedule_event( $manana->getTimestamp(), 'daily', self::EVENTO );
		}
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::EVENTO );
	}

	/** Si el mes anterior todavía no se envió, lo envía. */
	public static function diario(): void {
		$anterior = ( new DateTimeImmutable( 'first day of last month', Ajustes::zona() ) )->format( 'Y-m' );

		if ( get_option( self::OPCION_ULTIMO ) === $anterior ) {
			return;
		}

		if ( true === self::enviar( $anterior, 'Respaldo automático' ) ) {
			update_option( self::OPCION_ULTIMO, $anterior, false );
		}
	}

	public static function destinatario(): string {
		$correo = (string) Ajustes::todos()['correo_rrhh'];

		return is_email( $correo ) ? $correo : (string) get_option( 'admin_email' );
	}

	/** @return true|string true si se envió; si no, el motivo. */
	public static function enviar( string $mes, string $generadoPor ): true|string {
		$archivo = Exportes::registroMensual( $mes, $generadoPor );
		$carpeta = trailingslashit( wp_upload_dir()['basedir'] ) . 'pka-respaldos';

		if ( ! wp_mkdir_p( $carpeta ) ) {
			return 'No se pudo crear la carpeta temporal.';
		}

		// La carpeta nunca debe servirse por web.
		if ( ! file_exists( $carpeta . '/.htaccess' ) ) {
			file_put_contents( $carpeta . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $carpeta . '/index.php', "<?php\n// Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$ruta = $carpeta . '/' . $archivo['nombre'];
		file_put_contents( $ruta, $archivo['contenido'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$ajustes = Ajustes::todos();
		$enviado = wp_mail(
			self::destinatario(),
			'Registro de asistencia ' . $mes . ' · ' . $ajustes['razon_social'],
			"Adjunto el registro de asistencia de {$mes}.\n\n"
			. "Marcas del periodo: {$archivo['marcas']}\n"
			. "SHA-256 del archivo: {$archivo['hash']}\n\n"
			. "Guárdalo fuera del hosting: la norma pide conservar el registro 5 años.\n",
			array(),
			array( $ruta )
		);

		wp_delete_file( $ruta );

		Auditoria::registrar( 'respaldo.enviar', 'respaldo', null, null, array( 'mes' => $mes, 'hash' => $archivo['hash'], 'enviado' => $enviado, 'a' => self::destinatario() ) );

		return $enviado ? true : 'El correo no salió. Revisa la configuración de correo (SMTP) del sitio.';
	}
}
