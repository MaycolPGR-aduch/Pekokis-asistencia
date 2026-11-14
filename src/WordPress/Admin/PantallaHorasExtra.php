<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\AutorizacionSobretiempo;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Horarios;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Sobretiempo;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Horas extra: el jefe autoriza franjas de sobretiempo de su equipo
 * (antes o después del día) y ve quién se quedó fuera de horario sin autorización.
 */
final class PantallaHorasExtra {
	public const SLUG = 'pka-horas-extra';

	/** Cuántos días atrás se buscan permanencias sin autorizar. */
	private const DIAS_REVISION = 7;

	public static function registrar(): void {
		add_action( 'admin_post_pka_sobretiempo_crear', array( self::class, 'crear' ) );
		add_action( 'admin_post_pka_sobretiempo_anular', array( self::class, 'anular' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Permanencias fuera de horario sin autorizar del equipo en los últimos días.
	 *
	 * @return array<int, array{t: array<string, mixed>, dia: string, minutos: int}>
	 */
	public static function sinAutorizar( int $dias = self::DIAS_REVISION ): array {
		$alcance  = PantallaHoy::alcance();
		$personas = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => ! is_array( $alcance ) || in_array( (int) $t['id'], $alcance, true ) ) );
		$hasta    = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$desde    = ( new DateTimeImmutable( $hasta ) )->modify( '-' . $dias . ' days' )->format( 'Y-m-d' );
		$umbral   = Ajustes::reglasPago()->avisoPermanenciaMin;
		$lista    = array();

		foreach ( Jornadas::delRango( $personas, $desde, $hasta ) as $id => $porDia ) {
			foreach ( $porDia as $dia => $j ) {
				if ( $j->minutosSinAutorizar >= $umbral ) {
					$lista[] = array( 't' => self::persona( $personas, $id ), 'dia' => $dia, 'minutos' => $j->minutosSinAutorizar );
				}
			}
		}

		usort( $lista, static fn( $a, $b ) => strcmp( $b['dia'], $a['dia'] ) );

		return $lista;
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::APROBAR ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$alcance  = PantallaHoy::alcance();
		$personas = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => ( ! is_array( $alcance ) || in_array( (int) $t['id'], $alcance, true ) ) && ! Trabajadores::esPropio( (int) $t['id'] ) ) );
		$previo   = get_transient( 'pka_form_sobretiempo_' . get_current_user_id() );
		$errores  = is_array( $previo ) ? $previo['errores'] : array();
		$hoy      = wp_date( 'Y-m-d', null, Ajustes::zona() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo rellenan el formulario.
		$valores = is_array( $previo ) ? $previo['valores'] : array(
			'trabajador_id' => isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0,
			'fecha'         => isset( $_GET['fecha'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha'] ) ) : $hoy,
			'desde'         => isset( $_GET['desde'] ) ? sanitize_text_field( wp_unslash( $_GET['desde'] ) ) : '',
			'hasta'         => isset( $_GET['hasta'] ) ? sanitize_text_field( wp_unslash( $_GET['hasta'] ) ) : '',
			'compensacion'  => AutorizacionSobretiempo::PAGO,
		);
		$verAnuladas = isset( $_GET['estado'] ) && 'anulada' === $_GET['estado'];
		// phpcs:enable

		delete_transient( 'pka_form_sobretiempo_' . get_current_user_id() );

		$desdeLista = ( new DateTimeImmutable( $hoy ) )->modify( '-62 days' )->format( 'Y-m-d' );
		$filas      = Sobretiempo::listar( array( 'trabajadores' => $alcance, 'desde' => $desdeLista, 'estado' => $verAnuladas ? 'anulada' : 'vigente' ) );
		$pendientes = self::sinAutorizar();
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Horas extra</h1>
			<p>Solo cuenta como horas extra el tiempo trabajado <strong>dentro de una franja autorizada</strong>. Si alguien se queda fuera de su horario sin autorización, aparece abajo para que lo revises: la norma presume que es sobretiempo si no se aclara. Se puede autorizar antes o después del día.</p>

			<?php if ( $pendientes ) : ?>
				<h2>Tiempo fuera de horario sin autorizar (últimos <?php echo (int) self::DIAS_REVISION; ?> días)</h2>
				<table class="widefat striped" style="max-width:760px">
					<thead><tr><th>Trabajador</th><th>Día</th><th>Tiempo</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $pendientes as $p ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( PantallaDia::url( (int) $p['t']['id'], $p['dia'] ) ); ?>"><?php echo esc_html( $p['t']['apellidos'] . ', ' . $p['t']['nombres'] ); ?></a></td>
							<td><?php echo esc_html( $p['dia'] ); ?></td>
							<td><?php echo esc_html( PrePlanilla::horas( $p['minutos'] ) ); ?> h</td>
							<td>
								<?php if ( ! Trabajadores::esPropio( (int) $p['t']['id'] ) ) : ?>
									<a class="button" href="<?php echo esc_url( self::url( self::sugerencia( $p['t'], $p['dia'] ) ) ); ?>">Autorizar</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Si no corresponde pagarlo (por ejemplo, se quedó esperando a alguien), déjalo sin autorizar: no se paga, pero queda anotado en la pre-planilla como "sin autorizar".</p>
			<?php endif; ?>

			<h2>Autorizar horas extra</h2>
			<?php if ( ! $personas ) : ?>
				<p><?php echo is_array( $alcance ) ? 'No tienes trabajadores a tu cargo.' : 'No hay trabajadores activos.'; ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_sobretiempo_crear">
				<?php wp_nonce_field( 'pka_sobretiempo_crear' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pka-s-trabajador">Trabajador</label></th>
						<td>
							<select id="pka-s-trabajador" name="trabajador_id" required>
								<option value="">— Elige —</option>
								<?php foreach ( $personas as $t ) : ?>
									<option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( (int) ( $valores['trabajador_id'] ?? 0 ), (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php self::error( $errores, 'trabajador_id' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-s-fecha">Día</label></th>
						<td><input type="date" id="pka-s-fecha" name="fecha" required value="<?php echo esc_attr( (string) ( $valores['fecha'] ?? $hoy ) ); ?>"><?php self::error( $errores, 'fecha' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-s-desde">Franja</label></th>
						<td>
							<input type="time" id="pka-s-desde" name="desde" required value="<?php echo esc_attr( (string) ( $valores['desde'] ?? '' ) ); ?>">
							<label for="pka-s-hasta">a</label>
							<input type="time" id="pka-s-hasta" name="hasta" required value="<?php echo esc_attr( (string) ( $valores['hasta'] ?? '' ) ); ?>">
							<?php self::error( $errores, 'desde' ); ?><?php self::error( $errores, 'hasta' ); ?>
							<p class="description">Normalmente desde la hora de salida del horario. Si termina pasada la medianoche, pon la hora del día siguiente (por ejemplo, 22:00 a 01:00).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Compensación</th>
						<td>
							<?php foreach ( AutorizacionSobretiempo::COMPENSACIONES as $clave => $etiqueta ) : ?>
								<label style="margin-right:16px"><input type="radio" name="compensacion" value="<?php echo esc_attr( $clave ); ?>" <?php checked( (string) ( $valores['compensacion'] ?? AutorizacionSobretiempo::PAGO ), $clave ); ?>> <?php echo esc_html( $etiqueta ); ?></label>
							<?php endforeach; ?>
							<?php self::error( $errores, 'compensacion' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-s-motivo">Motivo</label></th>
						<td><input type="text" id="pka-s-motivo" name="motivo" class="regular-text" maxlength="255" required value="<?php echo esc_attr( (string) ( $valores['motivo'] ?? '' ) ); ?>"><?php self::error( $errores, 'motivo' ); ?></td>
					</tr>
				</table>
				<?php submit_button( 'Autorizar' ); ?>
			</form>
			<?php endif; ?>

			<h2><?php echo $verAnuladas ? 'Autorizaciones anuladas' : 'Autorizaciones de los últimos dos meses'; ?></h2>
			<p><a href="<?php echo esc_url( self::url() ); ?>">Vigentes</a> · <a href="<?php echo esc_url( self::url( array( 'estado' => 'anulada' ) ) ); ?>">Anuladas</a></p>
			<table class="widefat striped">
				<thead><tr><th>Trabajador</th><th>Día</th><th>Franja</th><th>Compensación</th><th>Motivo</th><th>Autorizó</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?>
					<tr><td colspan="7">No hay autorizaciones.</td></tr>
				<?php endif; ?>
				<?php foreach ( $filas as $s ) : ?>
					<?php $quien = get_userdata( (int) $s['autorizado_por'] ); ?>
					<tr>
						<td><?php echo esc_html( $s['apellidos'] . ', ' . $s['nombres'] ); ?></td>
						<td><?php echo esc_html( $s['fecha'] ); ?></td>
						<td><?php echo esc_html( substr( $s['desde'], 0, 5 ) . ' a ' . substr( $s['hasta'], 0, 5 ) ); ?></td>
						<td><?php echo esc_html( AutorizacionSobretiempo::COMPENSACIONES[ $s['compensacion'] ] ?? $s['compensacion'] ); ?></td>
						<td><?php echo esc_html( $s['motivo'] ); ?><?php echo 'anulada' === $s['estado'] ? '<br><em>Anulada: ' . esc_html( $s['motivo_anulacion'] ) . '</em>' : ''; ?></td>
						<td><?php echo esc_html( $quien ? $quien->display_name : '—' ); ?></td>
						<td>
							<?php if ( 'vigente' === $s['estado'] && ! Trabajadores::esPropio( (int) $s['trabajador_id'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
									<input type="hidden" name="action" value="pka_sobretiempo_anular">
									<input type="hidden" name="id" value="<?php echo esc_attr( $s['id'] ); ?>">
									<?php wp_nonce_field( 'pka_sobretiempo_anular' ); ?>
									<label class="screen-reader-text" for="pka-s-anular-<?php echo esc_attr( $s['id'] ); ?>">Motivo de la anulación</label>
									<input type="text" id="pka-s-anular-<?php echo esc_attr( $s['id'] ); ?>" name="motivo" placeholder="Por qué se anula" required>
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
		self::exigir( 'pka_sobretiempo_crear' );

		$entrada = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );

		if ( ! self::enAlcance( (int) ( $entrada['trabajador_id'] ?? 0 ) ) ) {
			wp_die( 'Ese trabajador no está a tu cargo.', 403 );
		}

		$result = Sobretiempo::crear( $entrada );

		if ( $result instanceof WP_Error ) {
			set_transient( 'pka_form_sobretiempo_' . get_current_user_id(), array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => $entrada ), 5 * MINUTE_IN_SECONDS );
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', 'Horas extra autorizadas.' );
		}

		Menu::volver( self::SLUG );
	}

	public static function anular(): void {
		self::exigir( 'pka_sobretiempo_anular' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$fila = Sobretiempo::buscar( $id );

		if ( ! $fila || ! self::enAlcance( (int) $fila['trabajador_id'] ) ) {
			wp_die( 'Esa autorización no es de tu equipo.', 403 );
		}

		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result = Sobretiempo::anular( $id, $motivo );

		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : 'Autorización anulada.' ) );
		Menu::volver( self::SLUG );
	}

	/** Franja sugerida para "Autorizar": desde el fin del horario hasta la última salida. */
	private static function sugerencia( array $t, string $dia ): array {
		$horario = Horarios::delDia( (int) $t['horario_id'], $dia );
		$marcas  = \Pekokis\Asistencia\WordPress\Marcas::delDia( (int) $t['id'], $dia );
		$zona    = Ajustes::zona();
		$args    = array( 'trabajador' => (int) $t['id'], 'fecha' => $dia );

		if ( $marcas ) {
			$args['hasta'] = end( $marcas )->marcadoUtc->setTimezone( $zona )->format( 'H:i' );
			$args['desde'] = $horario->laborable && $horario->salida ? substr( $horario->salida, 0, 5 ) : reset( $marcas )->marcadoUtc->setTimezone( $zona )->format( 'H:i' );
		}

		return $args;
	}

	private static function enAlcance( int $trabajadorId ): bool {
		$alcance = PantallaHoy::alcance();

		return ! is_array( $alcance ) || in_array( $trabajadorId, $alcance, true );
	}

	/** @param array<int, array<string, mixed>> $personas */
	private static function persona( array $personas, int $id ): array {
		foreach ( $personas as $p ) {
			if ( (int) $p['id'] === $id ) {
				return $p;
			}
		}

		return array( 'id' => $id, 'nombres' => '', 'apellidos' => '#' . $id, 'horario_id' => 0 );
	}

	/** @param array<string, string> $errores */
	private static function error( array $errores, string $campo ): void {
		if ( isset( $errores[ $campo ] ) ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html( $errores[ $campo ] ) . '</p>';
		}
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::APROBAR ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
