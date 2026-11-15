<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\InsercionEncadenada;
use Pekokis\Asistencia\WordPress\PrePlanillas;
use Pekokis\Asistencia\WordPress\Respaldo;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Exportar: descargar el registro mensual y el respaldo por correo.
 */
final class PantallaExportar {
	public const SLUG = 'pka-exportar';

	public static function registrar(): void {
		add_action( 'admin_post_pka_descargar_registro', array( self::class, 'descargar' ) );
		add_action( 'admin_post_pka_descargar_preplanilla', array( self::class, 'descargarPrePlanilla' ) );
		add_action( 'admin_post_pka_enviar_respaldo', array( self::class, 'enviar' ) );
		add_action( 'admin_post_pka_correo_respaldo', array( self::class, 'guardarCorreo' ) );
		add_action( 'admin_post_pka_webhook_respaldo', array( self::class, 'guardarWebhook' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::EXPORTAR ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$meses = Exportes::meses();
		$rota  = InsercionEncadenada::verificar( 'marcas' );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Exportar registro de asistencia</h1>

			<?php if ( null !== $rota ) : ?>
				<div class="notice notice-error inline"><p><strong>La cadena de marcas está rota desde la marca <?php echo (int) $rota; ?>.</strong> Alguien editó o borró marcas directamente en la base de datos. Avisa al administrador.</p></div>
			<?php else : ?>
				<p>✔ La cadena de marcas está íntegra: nadie las alteró fuera del sistema.</p>
			<?php endif; ?>

			<h2>Descargar</h2>
			<p>El Excel trae el registro diario (ingreso y salida por trabajador), todas las marcas (incluidas las anuladas) y una hoja de integridad.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_descargar_registro">
				<?php wp_nonce_field( 'pka_descargar_registro' ); ?>
				<label for="pka-mes">Mes</label>
				<select id="pka-mes" name="mes">
					<?php foreach ( $meses as $mes ) : ?>
						<option value="<?php echo esc_attr( $mes ); ?>"><?php echo esc_html( $mes ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary">Descargar Excel</button>
			</form>

			<?php if ( current_user_can( Capacidades::VER_SUELDOS ) ) : ?>
				<h class="pk-mt"2>Planilla de pagos</h2>
				<p>El pago de cada persona por recibos por honorarios: honorario, horas extra, de sábado, de domingo y de feriado con su tarifa, total y si ya se puede pagar ("Resolver incidencia" si tiene días incompletos o marcas por revisar). Trae el detalle por día y las <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaAjustes::SLUG ) . '#reglas' ); ?>">reglas</a>. Solo cuenta los días ya terminados. Tiene montos: no la compartas fuera de gerencia.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pka_descargar_preplanilla">
					<?php wp_nonce_field( 'pka_descargar_preplanilla' ); ?>
					<label for="pka-mes-pp">Mes</label>
					<select id="pka-mes-pp" name="mes">
						<?php foreach ( $meses as $mes ) : ?>
							<option value="<?php echo esc_attr( $mes ); ?>"><?php echo esc_html( $mes ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button">Descargar planilla de pagos</button>
				</form>
			<?php endif; ?>

			<h class="pk-mt"2>Respaldo por correo</h2>
			<p>Cada mes, el día 1 a las 06:00, se envía el registro del mes anterior a <strong><?php echo esc_html( Respaldo::destinatario() ); ?></strong>. La norma pide guardarlo 5 años: archívalo fuera del hosting.</p>

			<?php if ( current_user_can( Capacidades::AJUSTES ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-mb">
					<input type="hidden" name="action" value="pka_correo_respaldo">
					<?php wp_nonce_field( 'pka_correo_respaldo' ); ?>
					<label for="pka-correo">Enviar a</label>
					<input type="email" id="pka-correo" name="correo" class="regular-text" value="<?php echo esc_attr( (string) Ajustes::todos()['correo_rrhh'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
					<button class="button">Guardar</button>
				</form>
			<?php endif; ?>

			<?php if ( current_user_can( Capacidades::AJUSTES ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-mb">
					<input type="hidden" name="action" value="pka_webhook_respaldo">
					<?php wp_nonce_field( 'pka_webhook_respaldo' ); ?>
					<label for="pka-webhook">Copia en la nube (webhook de Make o n8n)</label><br>
					<input type="url" id="pka-webhook" name="webhook" class="large-text code" placeholder="https://hook.us1.make.com/…" value="<?php echo esc_attr( (string) \Pekokis\Asistencia\WordPress\Ajustes::todos()['webhook_respaldo'] ); ?>">
					<p class="description">Opcional. Además del correo, el Excel se envía a esta dirección (campo <code>archivo</code>, más <code>mes</code> y <code>hash</code>). En Make: un escenario con "Webhooks → Custom webhook" y "Google Drive → Upload a file". Déjalo vacío para no usarlo.</p>
					<button class="button">Guardar webhook</button>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_enviar_respaldo">
				<?php wp_nonce_field( 'pka_enviar_respaldo' ); ?>
				<label for="pka-mes-envio">Enviar ahora el mes</label>
				<select id="pka-mes-envio" name="mes">
					<?php foreach ( $meses as $mes ) : ?>
						<option value="<?php echo esc_attr( $mes ); ?>"><?php echo esc_html( $mes ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button">Enviar respaldo</button>
			</form>
		</div>
		<?php
	}

	public static function descargar(): void {
		self::exigir( 'pka_descargar_registro' );

		$mes = self::mes();
		$xls = Exportes::registroMensual( $mes, wp_get_current_user()->display_name );

		Auditoria::registrar( 'registro.descargar', 'registro', null, null, array( 'mes' => $mes, 'hash' => $xls['hash'] ) );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $xls['nombre'] . '"' );
		header( 'Content-Length: ' . strlen( $xls['contenido'] ) );
		header( 'X-Content-SHA256: ' . $xls['hash'] );
		echo $xls['contenido']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- archivo binario.
		exit;
	}

	public static function descargarPrePlanilla(): void {
		self::exigir( 'pka_descargar_preplanilla' );

		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para ver sueldos.', 403 );
		}

		$mes = self::mes();
		$xls = PrePlanillas::excel( $mes, wp_get_current_user()->display_name );

		Auditoria::registrar( 'preplanilla.descargar', 'registro', null, null, array( 'mes' => $mes, 'trabajadores' => $xls['trabajadores'], 'hash' => $xls['hash'] ) );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $xls['nombre'] . '"' );
		header( 'Content-Length: ' . strlen( $xls['contenido'] ) );
		echo $xls['contenido']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- archivo binario.
		exit;
	}

	public static function enviar(): void {
		self::exigir( 'pka_enviar_respaldo' );

		$resultado = Respaldo::enviar( self::mes(), wp_get_current_user()->display_name );
		$todoBien  = $resultado['correo'] && false !== $resultado['nube'];

		Avisos::agregar( $todoBien ? 'success' : 'error', esc_html( $resultado['mensaje'] ) );

		Menu::volver( self::SLUG );
	}

	public static function guardarCorreo(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_correo_respaldo' );

		$correo = isset( $_POST['correo'] ) ? sanitize_email( wp_unslash( $_POST['correo'] ) ) : '';

		if ( '' !== $correo && ! is_email( $correo ) ) {
			Avisos::agregar( 'error', 'Ese correo no es válido.' );
			Menu::volver( self::SLUG );
		}

		$ajustes                = Ajustes::todos();
		$antes                  = $ajustes['correo_rrhh'];
		$ajustes['correo_rrhh'] = $correo;
		update_option( Ajustes::OPCION, $ajustes );

		Auditoria::registrar( 'ajustes.correo_rrhh', 'ajustes', null, array( 'correo_rrhh' => $antes ), array( 'correo_rrhh' => $correo ) );
		Avisos::agregar( 'success', 'Correo guardado.' );
		Menu::volver( self::SLUG );
	}

	public static function guardarWebhook(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_webhook_respaldo' );

		$url = isset( $_POST['webhook'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['webhook'] ) ), array( 'https' ) ) : '';

		if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
			Avisos::agregar( 'error', 'La dirección del webhook no es válida (debe empezar con https://).' );
			Menu::volver( self::SLUG );
		}

		$ajustes                     = Ajustes::todos();
		$antes                       = (string) $ajustes['webhook_respaldo'];
		$ajustes['webhook_respaldo'] = $url;
		update_option( Ajustes::OPCION, $ajustes );

		// La URL de un webhook es un secreto: en el historial solo queda el dominio.
		$host = static fn( string $u ): string => '' === $u ? '—' : (string) wp_parse_url( $u, PHP_URL_HOST );
		Auditoria::registrar( 'ajustes.webhook', 'ajustes', null, array( 'webhook' => $host( $antes ) ), array( 'webhook' => $host( $url ) ) );

		Avisos::agregar( 'success', '' === $url ? 'Copia a la nube desactivada.' : 'Webhook guardado. Pruébalo con "Enviar respaldo".' );
		Menu::volver( self::SLUG );
	}

	private static function mes(): string {
		$mes = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado en exigir().

		if ( ! in_array( $mes, Exportes::meses(), true ) ) {
			wp_die( 'Mes no válido.', 400 );
		}

		return $mes;
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::EXPORTAR ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
