<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\ValidacionAusencia;
use Pekokis\Asistencia\WordPress\Ausencias;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Ausencias: vacaciones, descanso médico, licencias y permisos de días completos.
 */
final class PantallaAusencias {
	public const SLUG = 'pka-ausencias';

	public static function registrar(): void {
		add_action( 'admin_post_pka_ausencia_crear', array( self::class, 'crear' ) );
		add_action( 'admin_post_pka_ausencia_anular', array( self::class, 'anular' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$trabajador = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$estado     = isset( $_GET['estado'] ) && 'anulada' === $_GET['estado'] ? 'anulada' : 'vigente'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filas      = Ausencias::listar( array( 'trabajador' => $trabajador, 'estado' => $estado ) );
		$personas   = Trabajadores::listar();
		$previo     = get_transient( 'pka_form_ausencia_' . get_current_user_id() );
		$errores    = is_array( $previo ) ? $previo['errores'] : array();
		$valores    = is_array( $previo ) ? $previo['valores'] : array( 'trabajador_id' => $trabajador );

		delete_transient( 'pka_form_ausencia_' . get_current_user_id() );

		$nombreFiltro = '';
		foreach ( $personas as $p ) {
			if ( (int) $p['id'] === $trabajador ) {
				$nombreFiltro = $p['nombres'] . ' ' . $p['apellidos'];
			}
		}
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Ausencias justificadas</h1>
			<p>Vacaciones, descanso médico, licencias y permisos de días completos. Esos días el trabajador no aparece como ausente ni con falta. Para salidas de unas horas se usan los motivos de salida de la app.</p>

			<h2>Registrar una ausencia</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_ausencia_crear">
				<?php wp_nonce_field( 'pka_ausencia_crear' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pka-a-trabajador">Trabajador</label></th>
						<td>
							<select id="pka-a-trabajador" name="trabajador_id" required>
								<option value="">— Elige —</option>
								<?php foreach ( $personas as $t ) : ?>
									<option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( (int) ( $valores['trabajador_id'] ?? 0 ), (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php self::error( $errores, 'trabajador_id' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-a-tipo">Tipo</label></th>
						<td>
							<select id="pka-a-tipo" name="tipo">
								<?php foreach ( ValidacionAusencia::TIPOS as $clave => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( (string) ( $valores['tipo'] ?? '' ), $clave ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php self::error( $errores, 'tipo' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-a-desde">Desde</label></th>
						<td><input type="date" id="pka-a-desde" name="desde" required value="<?php echo esc_attr( (string) ( $valores['desde'] ?? '' ) ); ?>"><?php self::error( $errores, 'desde' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-a-hasta">Hasta (inclusive)</label></th>
						<td><input type="date" id="pka-a-hasta" name="hasta" required value="<?php echo esc_attr( (string) ( $valores['hasta'] ?? '' ) ); ?>"><?php self::error( $errores, 'hasta' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-a-nota">Nota o sustento</label></th>
						<td>
							<input type="text" id="pka-a-nota" name="nota" class="regular-text" maxlength="255" value="<?php echo esc_attr( (string) ( $valores['nota'] ?? '' ) ); ?>">
							<p class="description">Por ejemplo, el número del certificado (CITT) o de la papeleta. El documento lo guarda RR.HH. fuera del sistema: es un dato de salud y no debe subirse al sitio.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Registrar ausencia' ); ?>
			</form>

			<h2>
				<?php echo 'vigente' === $estado ? 'Ausencias registradas' : 'Ausencias anuladas'; ?>
				<?php echo '' !== $nombreFiltro ? '· ' . esc_html( $nombreFiltro ) : ''; ?>
			</h2>
			<p>
				<a href="<?php echo esc_url( self::url() ); ?>">Todas</a> ·
				<a href="<?php echo esc_url( self::url( array( 'estado' => 'anulada' ) ) ); ?>">Anuladas</a>
			</p>
			<table class="widefat striped">
				<thead><tr><th>Trabajador</th><th>Tipo</th><th>Desde</th><th>Hasta</th><th>Días</th><th>Nota</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?>
					<tr><td colspan="7">No hay ausencias.</td></tr>
				<?php endif; ?>
				<?php foreach ( $filas as $a ) : ?>
					<tr>
						<td><?php echo esc_html( $a['apellidos'] . ', ' . $a['nombres'] ); ?></td>
						<td><?php echo esc_html( ValidacionAusencia::TIPOS[ $a['tipo'] ] ?? $a['tipo'] ); ?></td>
						<td><?php echo esc_html( $a['desde'] ); ?></td>
						<td><?php echo esc_html( $a['hasta'] ); ?></td>
						<td><?php echo (int) ValidacionAusencia::dias( $a['desde'], $a['hasta'] ); ?></td>
						<td><?php echo esc_html( $a['nota'] ); ?><?php echo 'anulada' === $a['estado'] ? '<br><em>Anulada: ' . esc_html( $a['motivo_anulacion'] ) . '</em>' : ''; ?></td>
						<td>
							<?php if ( 'vigente' === $a['estado'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
									<input type="hidden" name="action" value="pka_ausencia_anular">
									<input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ); ?>">
									<?php wp_nonce_field( 'pka_ausencia_anular' ); ?>
									<label class="screen-reader-text" for="pka-a-anular-<?php echo esc_attr( $a['id'] ); ?>">Motivo de la anulación</label>
									<input type="text" id="pka-a-anular-<?php echo esc_attr( $a['id'] ); ?>" name="motivo" placeholder="Por qué se anula" required>
									<button class="button">Anular</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function crear(): void {
		self::exigir( 'pka_ausencia_crear' );

		$entrada = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$result  = Ausencias::crear( $entrada );

		if ( $result instanceof WP_Error ) {
			set_transient( 'pka_form_ausencia_' . get_current_user_id(), array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => $entrada ), 5 * MINUTE_IN_SECONDS );
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', 'Ausencia registrada.' );
		}

		Menu::volver( self::SLUG );
	}

	public static function anular(): void {
		self::exigir( 'pka_ausencia_anular' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result = Ausencias::anular( $id, $motivo );

		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : 'Ausencia anulada.' ) );
		Menu::volver( self::SLUG );
	}

	/** @param array<string, string> $errores */
	private static function error( array $errores, string $campo ): void {
		if ( isset( $errores[ $campo ] ) ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html( $errores[ $campo ] ) . '</p>';
		}
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
