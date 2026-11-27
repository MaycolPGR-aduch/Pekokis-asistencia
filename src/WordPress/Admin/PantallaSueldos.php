<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Sueldos;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Sueldos y tarifas (maquetas G8): el honorario y las cuatro
 * tarifas por hora de todos, en una sola tabla. Solo lo ve quien ve los pagos.
 *
 * Al guardar, cada persona con cambios recibe un registro nuevo desde la fecha
 * elegida (Sueldos::agregar cierra el anterior, que queda en su historial).
 * Los campos cambiados se resaltan y arriba se cuentan los cambios sin guardar.
 */
final class PantallaSueldos {
	public const SLUG = 'pka-sueldos';

	private const CAMPOS = array(
		'honorario'      => 'Honorario mensual',
		'tarifa_extra'   => 'Hora extra y sábado',
		'tarifa_domingo' => 'Hora domingo',
		'tarifa_feriado' => 'Hora feriado',
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_sueldos', array( self::class, 'guardar' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$personas = Trabajadores::listar();
		$hoy      = wp_date( 'Y-m-d', null, Ajustes::zona() );
		$previo   = get_transient( 'pka_form_sueldos_' . get_current_user_id() );
		$errores  = is_array( $previo ) ? $previo['errores'] : array();
		$valores  = is_array( $previo ) ? $previo['valores'] : array();
		$monto    = static fn( ?int $c ): string => null === $c ? '' : number_format( $c / 100, 2, '.', '' );

		delete_transient( 'pka_form_sueldos_' . get_current_user_id() );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Sueldos y tarifas <span class="pk-h1-der" id="pk-sin-guardar" hidden>0 cambios sin guardar</span></h1>
			<p class="description">Cada persona tiene su propio honorario y su precio por hora. El honorario incluye las horas normales; cada hora adicional va con su tarifa. El valor hora es el honorario ÷ <?php echo (int) Sueldo::HORAS_MES; ?> y se usa para el sábado sin semana completa y la madrugada. El sábado con la semana completa va con la tarifa extra. Quien cobra por horas se cambia en su perfil. Los trabajadores nunca ven estos montos.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pk-sueldos" class="pk-sueldos">
				<input type="hidden" name="action" value="pka_guardar_sueldos">
				<?php wp_nonce_field( 'pka_guardar_sueldos' ); ?>
				<div class="pk-desplaza">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Persona</th>
								<?php foreach ( self::CAMPOS as $etiqueta ) : ?>
									<th><?php echo esc_html( $etiqueta ); ?></th>
								<?php endforeach; ?>
								<th>Valor hora</th>
								<th>Vigente desde</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $personas ) : ?>
							<tr><td colspan="7">No hay trabajadores activos.</td></tr>
						<?php endif; ?>
						<?php foreach ( $personas as $t ) : ?>
							<?php
							$id     = (int) $t['id'];
							$s      = Sueldos::vigente( $id );
							$actual = array(
								'honorario'      => $monto( $s?->honorarioCentimos ),
								'tarifa_extra'   => $monto( $s?->tarifaExtra ),
								'tarifa_domingo' => $monto( $s?->tarifaDomingo ),
								'tarifa_feriado' => $monto( $s?->tarifaFeriado ),
							);
							$propio = Trabajadores::esPropio( $id );
							?>
							<tr class="<?php echo isset( $errores[ $id ] ) ? 'pk-fila-alerta' : ''; ?>">
								<td>
									<span class="pk-av"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $t['nombres'], 0, 1 ) . mb_substr( (string) $t['apellidos'], 0, 1 ) ) ); ?></span><a href="<?php echo esc_url( PantallaFicha::url( $id, 'pagos' ) ); ?>"><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></a>
									<?php if ( ! $s ) : ?>
										<span class="pk-chip error">Sin honorario</span>
									<?php elseif ( $s->porHoras() ) : ?>
										<span class="pk-chip info">Por horas</span>
									<?php endif; ?>
									<?php if ( isset( $errores[ $id ] ) ) : ?>
										<br><span class="pk-txt-error"><?php echo esc_html( implode( ' ', $errores[ $id ] ) ); ?></span>
									<?php endif; ?>
								</td>
								<?php foreach ( self::CAMPOS as $campo => $etiqueta ) : ?>
									<td>
										<label class="screen-reader-text" for="pk-s-<?php echo esc_attr( $campo . '-' . $id ); ?>"><?php echo esc_html( $etiqueta . ' de ' . Trabajadores::nombreCompleto( $t ) ); ?></label>
										<span class="pk-soles">S/ <input type="text" inputmode="decimal" class="pk-monto" id="pk-s-<?php echo esc_attr( $campo . '-' . $id ); ?>" name="s[<?php echo (int) $id; ?>][<?php echo esc_attr( $campo ); ?>]" value="<?php echo esc_attr( (string) ( $valores[ $id ][ $campo ] ?? $actual[ $campo ] ) ); ?>" data-antes="<?php echo esc_attr( $actual[ $campo ] ); ?>" <?php disabled( $propio ); ?>></span>
										<input type="hidden" name="antes[<?php echo (int) $id; ?>][<?php echo esc_attr( $campo ); ?>]" value="<?php echo esc_attr( $actual[ $campo ] ); ?>">
									</td>
								<?php endforeach; ?>
								<td class="pk-chico pk-valor-hora" data-horas="<?php echo (int) Sueldo::HORAS_MES; ?>"><?php echo $s ? esc_html( Sueldo::soles( $s->valorHora() ) ) : '—'; ?></td>
								<td class="pk-chico"><?php echo $s ? esc_html( wp_date( 'j M Y', strtotime( $s->vigenteDesde . ' 12:00:00' ) ) ) : '—'; ?><?php echo $propio ? '<br><em>Es tuyo: lo cambia otra persona.</em>' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="pk-caja pk-fila pk-mt-s">
					<label for="pk-s-desde"><strong>Los cambios rigen desde</strong></label>
					<input type="date" id="pk-s-desde" name="vigente_desde" value="<?php echo esc_attr( (string) ( $valores['vigente_desde'] ?? $hoy ) ); ?>" required>
					<label class="screen-reader-text" for="pk-s-nota">Nota</label>
					<input type="text" id="pk-s-nota" name="nota" class="pk-crece" maxlength="255" placeholder="Nota (opcional): por ejemplo, aumento de campaña" value="<?php echo esc_attr( (string) ( $valores['nota'] ?? '' ) ); ?>">
					<button class="button button-primary">Guardar cambios</button>
				</div>
				<p class="description">Solo se guardan las personas con cambios. Lo anterior queda en el historial de cada una (ficha → Pagos y recibos) y en la auditoría.</p>
			</form>
		</div>
		<?php
	}

	public static function guardar(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_guardar_sueldos' );

		$nuevos  = (array) wp_unslash( $_POST['s'] ?? array() );
		$antes   = (array) wp_unslash( $_POST['antes'] ?? array() );
		$desde   = isset( $_POST['vigente_desde'] ) ? sanitize_text_field( wp_unslash( $_POST['vigente_desde'] ) ) : '';
		$nota    = isset( $_POST['nota'] ) ? sanitize_text_field( wp_unslash( $_POST['nota'] ) ) : '';
		$r       = self::aplicar( $nuevos, $antes, $desde, $nota );

		if ( $r['errores'] ) {
			set_transient( 'pka_form_sueldos_' . get_current_user_id(), array( 'errores' => $r['errores'], 'valores' => array_map( static fn( $v ) => is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( (string) $v ), $nuevos ) + array( 'vigente_desde' => $desde, 'nota' => $nota ) ), 5 * MINUTE_IN_SECONDS );
			Avisos::agregar( 'error', esc_html( count( $r['errores'] ) . ( 1 === count( $r['errores'] ) ? ' persona tiene' : ' personas tienen' ) . ' datos por corregir.' . ( $r['guardados'] ? ' Las demás (' . $r['guardados'] . ') se guardaron.' : '' ) ) );
		} elseif ( $r['guardados'] ) {
			Avisos::agregar( 'success', esc_html( 'Listo: ' . $r['guardados'] . ( 1 === $r['guardados'] ? ' persona actualizada.' : ' personas actualizadas.' ) ) );
		} else {
			Avisos::agregar( 'warning', 'No había cambios para guardar.' );
		}

		Menu::volver( self::SLUG );
	}

	/**
	 * Guarda solo las filas que cambiaron.
	 *
	 * @param array<int|string, array<string, string>> $nuevos id => campo => valor
	 * @param array<int|string, array<string, string>> $antes  id => campo => valor mostrado
	 * @return array{guardados: int, errores: array<int, string[]>}
	 */
	public static function aplicar( array $nuevos, array $antes, string $desde, string $nota ): array {
		$guardados = 0;
		$errores   = array();

		foreach ( $nuevos as $id => $fila ) {
			$id   = (int) $id;
			$fila = array_map( static fn( $v ): string => trim( (string) $v ), (array) $fila );

			if ( ! $id || array_intersect_key( $fila, self::CAMPOS ) == array_intersect_key( array_map( static fn( $v ): string => trim( (string) $v ), (array) ( $antes[ $id ] ?? array() ) ), self::CAMPOS ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				continue;
			}

			// El modo (fijo o por horas) se conserva: se cambia en el perfil.
			$vigente = Sueldos::vigente( $id );
			$r       = Sueldos::agregar( $id, array_intersect_key( $fila, self::CAMPOS ) + array( 'vigente_desde' => $desde, 'nota' => $nota, 'modo' => $vigente?->modo, 'aparte' => $vigente ? $vigente->aparte : array() ) );

			if ( is_wp_error( $r ) ) {
				$errores[ $id ] = array_values( (array) ( $r->get_error_data()['errores'] ?? array( $r->get_error_message() ) ) );
			} else {
				++$guardados;
			}
		}

		return array( 'guardados' => $guardados, 'errores' => $errores );
	}
}
