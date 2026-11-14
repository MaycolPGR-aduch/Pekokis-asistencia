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

		$resultado = self::enviar( $anterior, 'Respaldo automático' );

		// Basta con que haya salido por un canal; el otro queda en el historial como fallido.
		if ( $resultado['correo'] || true === $resultado['nube'] ) {
			update_option( self::OPCION_ULTIMO, $anterior, false );
		}
	}

	public static function destinatario(): string {
		$correo = (string) Ajustes::todos()['correo_rrhh'];

		return is_email( $correo ) ? $correo : (string) get_option( 'admin_email' );
	}

	/**
	 * Envía el Excel del mes por correo y, si está configurado, a un webhook
	 * (por ejemplo un escenario de Make que lo guarda en Google Drive).
	 *
	 * @return array{correo: bool, nube: bool|null, mensaje: string} nube = null si no hay webhook.
	 */
	public static function enviar( string $mes, string $generadoPor ): array {
		$archivo = Exportes::registroMensual( $mes, $generadoPor );
		$carpeta = trailingslashit( wp_upload_dir()['basedir'] ) . 'pka-respaldos';

		if ( ! wp_mkdir_p( $carpeta ) ) {
			return array( 'correo' => false, 'nube' => null, 'mensaje' => 'No se pudo crear la carpeta temporal.' );
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

		$nube = self::alWebhook( $archivo, $mes );

		Auditoria::registrar(
			'respaldo.enviar',
			'respaldo',
			null,
			null,
			array( 'mes' => $mes, 'hash' => $archivo['hash'], 'enviado' => $enviado, 'a' => self::destinatario() ) + ( null === $nube ? array() : array( 'nube' => $nube ) )
		);

		$partes = array( $enviado ? 'Correo enviado a ' . self::destinatario() . '.' : 'El correo no salió: revisa la configuración de correo (SMTP) del sitio.' );

		if ( null !== $nube ) {
			$partes[] = $nube ? 'Copia enviada a la nube.' : 'La copia a la nube falló: revisa el webhook.';
		}

		return array( 'correo' => $enviado, 'nube' => $nube, 'mensaje' => implode( ' ', $partes ) );
	}

	/**
	 * POST multipart al webhook con el archivo (campo "archivo") y sus datos.
	 * Lleva una firma HMAC del hash en la cabecera X-PKA-Firma para que el
	 * receptor pueda comprobar que viene de este sistema.
	 *
	 * @param array{nombre: string, contenido: string, hash: string, marcas: int} $archivo
	 * @return bool|null null si no hay webhook configurado.
	 */
	private static function alWebhook( array $archivo, string $mes ): ?bool {
		$url = (string) Ajustes::todos()['webhook_respaldo'];

		// La URL ya se validó al guardarla en Exportar; aquí basta con que sea https.
		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return null;
		}

		$limite = 'pka' . bin2hex( random_bytes( 8 ) );
		$campos = array( 'mes' => $mes, 'hash' => $archivo['hash'], 'marcas' => (string) $archivo['marcas'], 'empresa' => (string) Ajustes::todos()['razon_social'] );
		$cuerpo = '';

		foreach ( $campos as $nombre => $valor ) {
			$cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$nombre}\"\r\n\r\n{$valor}\r\n";
		}

		$cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$archivo['nombre']}\"\r\n"
			. "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n"
			. $archivo['contenido'] . "\r\n--{$limite}--\r\n";

		$respuesta = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $limite,
					'X-PKA-Firma'  => hash_hmac( 'sha256', $archivo['hash'], Secretos::clave() ),
				),
				'body'    => $cuerpo,
			)
		);

		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );

		return ! is_wp_error( $respuesta ) && $codigo >= 200 && $codigo < 300;
	}
}
