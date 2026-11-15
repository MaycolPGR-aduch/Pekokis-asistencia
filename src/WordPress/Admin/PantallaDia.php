<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Accion;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Correcciones;
use Pekokis\Asistencia\WordPress\Marcas;
use Pekokis\Asistencia\WordPress\Motivos;
use Pekokis\Asistencia\WordPress\Seguimiento;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Detalle del día de un trabajador (marcas, anuladas incluidas), marca manual y
 * anulación; y la bandeja de solicitudes "olvidé marcar" y de días pendientes.
 */
final class PantallaDia {
	public const ORIGENES = array(
		'trabajador' => 'Celular',
		'manual'     => 'Manual del jefe',
		'correccion' => 'Corrección aprobada',
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_marca_manual', array( self::class, 'marcaManual' ) );
		add_action( 'admin_post_pka_anular_marca', array( self::class, 'anular' ) );
		add_action( 'admin_post_pka_resolver_correccion', array( self::class, 'resolver' ) );
	}

	public static function url( int $trabajadorId, string $dia ): string {
		return add_query_arg( array( 'page' => Menu::SLUG, 'vista' => 'dia', 'trabajador' => $trabajadorId, 'dia' => $dia ), admin_url( 'admin.php' ) );
	}

	/** Resumen del cálculo del día: horas trabajadas, tardanza y horas que se pagan aparte del honorario. */
	private static function calculo( array $t, string $dia ): void {
		$j = \Pekokis\Asistencia\WordPress\Jornadas::delDia( $t, $dia );

		if ( ! $j || ( ! $j->trabajoAlgo() && \Pekokis\Asistencia\Nucleo\CalculoJornada::DESCANSO === $j->estado ) ) {
			return;
		}

		$h      = static fn( int $m ): string => \Pekokis\Asistencia\Nucleo\PrePlanilla::horas( $m );
		$partes = array( 'Trabajó ' . $h( $j->minutosTrabajados ) . ' h' . ( $j->laborable ? ' de una jornada de ' . $h( $j->minutosEsperados ) . ' h' : '' ) );

		foreach ( array(
			array( $j->minutosExtra, 'h extra' ),
			array( $j->minutosSabado, 'h de sábado' ),
			array( $j->minutosDomingo, 'h de domingo' ),
			array( $j->minutosFeriado, 'h de feriado' ),
		) as [ $min, $texto ] ) {
			if ( $min > 0 ) {
				$partes[] = $h( $min ) . ' ' . $texto;
			}
		}

		foreach ( array(
			array( $j->minutosTardanza, 'min de tardanza' ),
			array( $j->minutosExcesoRefrigerio, 'min de más en la comida' ),
			array( $j->minutosComidaDescontada, 'min de comida descontada (no la marcó)' ),
			array( $j->minutosSalidaAnticipada, 'min de salida anticipada' ),
			array( $j->minutosSalidasPagadas, 'min en salidas pagadas' ),
		) as [ $min, $texto ] ) {
			if ( $min > 0 ) {
				$partes[] = $min . ' ' . $texto;
			}
		}
		?>
		<div class="notice notice-<?php echo in_array( $j->estado, array( \Pekokis\Asistencia\Nucleo\CalculoJornada::FALTA, \Pekokis\Asistencia\Nucleo\CalculoJornada::INCOMPLETO ), true ) ? 'warning' : 'info'; ?> inline">
			<p>
				<strong><?php echo esc_html( \Pekokis\Asistencia\Nucleo\CalculoJornada::ESTADOS[ $j->estado ] ?? $j->estado ); ?>.</strong>
				<?php echo esc_html( implode( ' · ', $partes ) . '.' ); ?>
			</p>
		</div>
		<?php
	}

	public static function pintar(): void {
		$id  = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dia = isset( $_GET['dia'] ) ? sanitize_text_field( wp_unslash( $_GET['dia'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t   = Trabajadores::buscar( $id );

		if ( ! $t || ! ValidacionTrabajador::esFecha( $dia ) || ! self::puedeVer( $id ) ) {
			echo '<div class="wrap"><p>No se encontró ese día o no es de tu equipo.</p></div>';
			return;
		}

		$zona   = Ajustes::zona();
		$filas  = Marcas::filasConAnuladas( $id, $dia );
		$antes  = ( new DateTimeImmutable( $dia ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$despues = ( new DateTimeImmutable( $dia ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$puedeCorregir = current_user_can( Capacidades::MARCA_MANUAL );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ); ?>">← Volver a Hoy</a></p>
			<h1><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?> · <?php echo esc_html( wp_date( 'l j \d\e F', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ) ); ?></h1>
			<p>
				<a href="<?php echo esc_url( self::url( $id, $antes ) ); ?>">← Día anterior</a> ·
				<a href="<?php echo esc_url( self::url( $id, $despues ) ); ?>">Día siguiente →</a> ·
				<a href="<?php echo esc_url( PantallaInforme::url( $id, substr( $dia, 0, 7 ) ) ); ?>">Informe del mes</a>
				<?php if ( current_user_can( Capacidades::AUDITORIA ) ) : ?>
					· <a href="<?php echo esc_url( PantallaHistorial::url( array( 'trabajador' => $id ) ) ); ?>">Historial de este trabajador</a>
				<?php endif; ?>
			</p>

			<?php
			$ausencia = \Pekokis\Asistencia\WordPress\Ausencias::delDia( $id, $dia );
			$feriado  = \Pekokis\Asistencia\WordPress\Calendario::descripcion( $dia );
			?>
			<?php if ( null !== $ausencia || '' !== $feriado ) : ?>
				<div class="notice notice-info inline"><p><strong><?php echo esc_html( null !== $ausencia ? 'Ausencia justificada: ' . $ausencia : $feriado ); ?>.</strong> Este día no cuenta como falta.</p></div>
			<?php endif; ?>

			<?php self::calculo( $t, $dia ); ?>

			<table class="widefat striped">
				<thead><tr><th>Hora</th><th>Marca</th><th>Prueba</th><th>Origen</th><th>Nota</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?>
					<tr><td colspan="6">No hay marcas este día.</td></tr>
				<?php endif; ?>
				<?php foreach ( $filas as $m ) : ?>
					<?php
					$motivo  = Motivos::porClave( $m['motivo_clave'] );
					$anulada = null !== $m['anulada'];
					$estilo  = $anulada ? 'text-decoration:line-through;color:#8c8f94' : '';
					?>
					<tr>
						<td style="<?php echo esc_attr( $estilo ); ?>"><strong><?php echo esc_html( ( new DateTimeImmutable( $m['marcado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona )->format( 'H:i' ) ); ?></strong></td>
						<td style="<?php echo esc_attr( $estilo ); ?>"><?php echo esc_html( 'entrada' === $m['tipo'] ? 'Entrada' : 'Salida · ' . ( $motivo ? $motivo->nombre : $m['motivo_clave'] ) ); ?></td>
						<td><?php echo esc_html( Marcas::etiquetaPrueba( PruebaPresencia::from( $m['prueba'] ) ) ); ?></td>
						<td><?php echo esc_html( self::ORIGENES[ $m['origen'] ] ?? $m['origen'] ); ?></td>
						<td><?php echo esc_html( $m['nota'] ); ?><?php echo $anulada ? '<br><em>Anulada: ' . esc_html( $m['anulada'] ) . '</em>' : ''; ?></td>
						<td>
							<?php if ( $puedeCorregir && ! $anulada ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
									<input type="hidden" name="action" value="pka_anular_marca">
									<input type="hidden" name="marca" value="<?php echo esc_attr( $m['id'] ); ?>">
									<?php wp_nonce_field( 'pka_anular_marca' ); ?>
									<label class="screen-reader-text" for="pka-anular-<?php echo esc_attr( $m['id'] ); ?>">Motivo de la anulación</label>
									<input type="text" id="pka-anular-<?php echo esc_attr( $m['id'] ); ?>" name="justificacion" placeholder="Por qué se anula" required>
									<button class="button">Anular</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Las marcas nunca se borran: una marca anulada queda tachada y deja de contar.</p>

			<?php if ( $puedeCorregir && Trabajadores::ACTIVO === $t['estado'] ) : ?>
				<h2>Registrar una marca que faltó</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pka_marca_manual">
					<input type="hidden" name="trabajador" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="dia" value="<?php echo esc_attr( $dia ); ?>">
					<?php wp_nonce_field( 'pka_marca_manual' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="pka-manual-hora">Hora</label></th>
							<td><input type="time" id="pka-manual-hora" name="hora" required> <span class="description">Hora de Lima, del <?php echo esc_html( $dia ); ?>.</span></td>
						</tr>
						<tr>
							<th scope="row"><label for="pka-manual-accion">Marca</label></th>
							<td>
								<select id="pka-manual-accion" name="accion_marca">
									<option value="<?php echo esc_attr( Accion::ENTRADA->value ); ?>">Entrada o regreso</option>
									<?php foreach ( Motivos::todos() as $motivo ) : ?>
										<?php if ( $motivo->activo ) : ?>
											<option value="salida:<?php echo esc_attr( $motivo->clave ); ?>">Salida · <?php echo esc_html( $motivo->nombre ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="pka-manual-justificacion">Justificación</label></th>
							<td><input type="text" id="pka-manual-justificacion" name="justificacion" class="regular-text" required></td>
						</tr>
					</table>
					<?php submit_button( 'Registrar marca manual', 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Bandejas que se muestran en "Hoy": solicitudes pendientes y días por revisar. */
	public static function bandejas( ?array $alcance, string $hoy ): void {
		$zona        = Ajustes::zona();
		$solicitudes = current_user_can( Capacidades::APROBAR ) ? Correcciones::pendientes( $alcance ) : array();
		$pendientes  = Seguimiento::pendientes( $alcance, $hoy );
		?>
		<h2 style="margin-top:32px">Solicitudes "olvidé marcar" (<?php echo count( $solicitudes ); ?>)</h2>
		<?php if ( ! $solicitudes ) : ?>
			<p>No hay solicitudes pendientes.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Trabajador</th><th>Marca pedida</th><th>Qué pasó</th><th style="width:44%">Decisión</th></tr></thead>
				<tbody>
				<?php foreach ( $solicitudes as $c ) : ?>
					<?php
					$momento = ( new DateTimeImmutable( $c['marcado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona );
					$motivo  = Motivos::porClave( $c['motivo_clave'] );
					?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( (int) $c['trabajador_id'], Ajustes::diaLaboral()->de( $momento ) ) ); ?>"><?php echo esc_html( $c['apellidos'] . ', ' . $c['nombres'] ); ?></a></td>
						<td><?php echo esc_html( $momento->format( 'd/m H:i' ) . ' · ' . ( 'entrada' === $c['tipo'] ? 'Entrada' : 'Salida · ' . ( $motivo ? $motivo->nombre : '' ) ) ); ?></td>
						<td><?php echo esc_html( $c['justificacion'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
								<input type="hidden" name="action" value="pka_resolver_correccion">
								<input type="hidden" name="correccion" value="<?php echo esc_attr( $c['id'] ); ?>">
								<?php wp_nonce_field( 'pka_resolver_correccion' ); ?>
								<label for="pka-hora-c<?php echo esc_attr( $c['id'] ); ?>" title="Si la hora pedida no es la correcta, cámbiala: el trabajador verá «Aprobada con corrección».">Hora final</label>
								<input type="time" id="pka-hora-c<?php echo esc_attr( $c['id'] ); ?>" name="hora_final" value="<?php echo esc_attr( $momento->format( 'H:i' ) ); ?>">
								<label class="screen-reader-text" for="pka-nota-c<?php echo esc_attr( $c['id'] ); ?>">Respuesta</label>
								<input type="text" id="pka-nota-c<?php echo esc_attr( $c['id'] ); ?>" name="nota" placeholder="Respuesta (obligatoria si rechazas)" style="flex:1;min-width:150px">
								<button class="button button-primary" name="decision" value="aprobar">Aprobar</button>
								<button class="button" name="decision" value="rechazar">Rechazar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:32px">Días anteriores por revisar (<?php echo count( $pendientes ); ?>)</h2>
		<p class="description">Últimos 7 días: días que quedaron sin salida y días laborables sin ninguna marca.</p>
		<?php if ( ! $pendientes ) : ?>
			<p>Todo en orden.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Día</th><th>Trabajador</th><th>Qué pasa</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $pendientes as $p ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'D d/m', ( new DateTimeImmutable( $p['dia'] . ' 12:00', $zona ) )->getTimestamp(), $zona ) ); ?></td>
						<td><?php echo esc_html( $p['trabajador']['apellidos'] . ', ' . $p['trabajador']['nombres'] ); ?></td>
						<td><?php echo Seguimiento::INCOMPLETO === $p['tipo'] ? 'Sin marca de salida' : '<span style="color:#b32d2e">Sin marcas (¿falta?)</span>'; ?></td>
						<td><a href="<?php echo esc_url( self::url( (int) $p['trabajador']['id'], $p['dia'] ) ); ?>">Ver y corregir</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	public static function marcaManual(): void {
		self::exigir( Capacidades::MARCA_MANUAL, 'pka_marca_manual' );

		$id     = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$dia    = isset( $_POST['dia'] ) ? sanitize_text_field( wp_unslash( $_POST['dia'] ) ) : '';
		$hora   = isset( $_POST['hora'] ) ? sanitize_text_field( wp_unslash( $_POST['hora'] ) ) : '';
		$valor  = isset( $_POST['accion_marca'] ) ? sanitize_text_field( wp_unslash( $_POST['accion_marca'] ) ) : '';
		$texto  = isset( $_POST['justificacion'] ) ? sanitize_text_field( wp_unslash( $_POST['justificacion'] ) ) : '';
		$motivo = str_starts_with( $valor, 'salida:' ) ? Motivos::porClave( substr( $valor, 7 ) ) : null;
		$accion = $motivo ? self::accionDeMotivo( $motivo->categoria->value ) : Accion::ENTRADA;

		// Una hora antes del corte (04:00) pertenece al día laboral anterior: se registra en el día siguiente del calendario.
		$fecha = $dia;
		if ( '' !== $hora && (int) substr( $hora, 0, 2 ) < (int) Ajustes::todos()['hora_corte'] ) {
			$fecha = ( new DateTimeImmutable( $dia ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}

		if ( ! self::puedeVer( $id ) ) {
			$result = new WP_Error( 'pka_alcance', 'Ese trabajador no es de tu equipo.' );
		} elseif ( Trabajadores::esPropio( $id ) ) {
			$result = new WP_Error( 'pka_propia', 'No puedes registrar marcas manuales para ti: pídeselo a otra persona con permiso.' );
		} else {
			$result = Correcciones::marcaManual( $id, $fecha, $hora, $accion, $motivo?->clave, $texto );
		}

		self::avisar( $result, 'Marca registrada.' );
		Menu::volverA( self::url( $id, $dia ) );
	}

	public static function anular(): void {
		global $wpdb;

		self::exigir( Capacidades::MARCA_MANUAL, 'pka_anular_marca' );

		$marca = isset( $_POST['marca'] ) ? absint( $_POST['marca'] ) : 0;
		$texto = isset( $_POST['justificacion'] ) ? sanitize_text_field( wp_unslash( $_POST['justificacion'] ) ) : '';
		$tabla = \Pekokis\Asistencia\WordPress\Tablas::nombre( 'marcas' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT trabajador_id, dia_laboral FROM {$tabla} WHERE id = %d", $marca ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila || ! self::puedeVer( (int) $fila['trabajador_id'] ) ) {
			Avisos::agregar( 'error', 'Esa marca no existe o no es de tu equipo.' );
			Menu::volver( Menu::SLUG );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			Avisos::agregar( 'error', 'No puedes anular tus propias marcas: pídeselo a otra persona con permiso.' );
			Menu::volverA( self::url( (int) $fila['trabajador_id'], $fila['dia_laboral'] ) );
		}

		self::avisar( Correcciones::anular( $marca, $texto ), 'Marca anulada.' );
		Menu::volverA( self::url( (int) $fila['trabajador_id'], $fila['dia_laboral'] ) );
	}

	public static function resolver(): void {
		self::exigir( Capacidades::APROBAR, 'pka_resolver_correccion' );

		$id       = isset( $_POST['correccion'] ) ? absint( $_POST['correccion'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$nota     = isset( $_POST['nota'] ) ? sanitize_text_field( wp_unslash( $_POST['nota'] ) ) : '';
		$horaFin  = isset( $_POST['hora_final'] ) ? sanitize_text_field( wp_unslash( $_POST['hora_final'] ) ) : '';
		$propia   = array_filter( Correcciones::pendientes( PantallaHoy::alcance() ), static fn( array $c ): bool => (int) $c['id'] === $id );

		if ( ! $propia ) {
			$result = new WP_Error( 'pka_alcance', 'Esa solicitud ya se resolvió o no es de tu equipo.' );
		} elseif ( Trabajadores::esPropio( (int) array_values( $propia )[0]['trabajador_id'] ) ) {
			$result = new WP_Error( 'pka_propia', 'No puedes aprobar tus propias solicitudes: lo hace otra persona con permiso.' );
		} else {
			$result = 'aprobar' === $decision ? Correcciones::aprobar( $id, $nota, $horaFin ) : Correcciones::rechazar( $id, $nota );
		}

		self::avisar( $result, 'aprobar' === $decision ? 'Solicitud aprobada: la marca ya cuenta.' : 'Solicitud rechazada.' );
		Menu::volver( PantallaSolicitudes::SLUG );
	}

	private static function accionDeMotivo( string $categoria ): Accion {
		return match ( $categoria ) {
			'refrigerio'  => Accion::SALIDA_REFRIGERIO,
			'fin_jornada' => Accion::FIN_JORNADA,
			default       => Accion::SALIDA_TEMPORAL,
		};
	}

	private static function puedeVer( int $trabajadorId ): bool {
		$alcance = PantallaHoy::alcance();

		return null === $alcance || in_array( $trabajadorId, $alcance, true );
	}

	private static function avisar( mixed $result, string $exito ): void {
		if ( $result instanceof WP_Error ) {
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', esc_html( $exito ) );
		}
	}

	private static function exigir( string $capacidad, string $accion ): void {
		if ( ! current_user_can( $capacidad ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
