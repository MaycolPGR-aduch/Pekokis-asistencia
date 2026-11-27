<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\PasosDelDia;
use Pekokis\Asistencia\Nucleo\VistaDia;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Correcciones;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Marcas;
use Pekokis\Asistencia\WordPress\Revisiones;
use Pekokis\Asistencia\WordPress\Tablas;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Registros y revisión (maquetas G4 y G4B).
 *
 * Una fila por persona y día: entrada, comida, salida, horas y estado. Los
 * días con problemas (incompletos, faltas, por revisar, fuera de reglas) van
 * primero. "Editar" abre un panel lateral con los cuatro pasos del día, el
 * valor anterior y el motivo obligatorio; el cambio se guarda de una vez
 * (Correcciones::editarDia) y queda en la auditoría.
 */
final class PantallaRegistros {
	public const SLUG = 'pka-registros';

	private const MAX_DIAS = 31;

	/** estado => [texto, tipo de chip, orden (primero lo urgente)]. */
	private const ESTADOS = array(
		'incompleto' => array( 'Incompleto', 'error', 0 ),
		'falta'      => array( 'Falta', 'error', 1 ),
		'revisar'    => array( 'Por revisar', 'aviso', 2 ),
		'fuera'      => array( 'Fuera de reglas', 'aviso', 3 ),
		'medianoche' => array( 'Pasó de medianoche', 'info', 4 ),
		'editado'    => array( 'Editado', 'info', 5 ),
		'en_curso'   => array( 'En curso', 'gris', 6 ),
		'normal'     => array( 'Normal', 'gris', 7 ),
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_editar_dia', array( self::class, 'guardar' ) );
	}

	/** @return array{persona: int, desde: string, hasta: string, estado: string} */
	private static function filtros(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$hoy   = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$fecha = static fn( string $k, string $porDefecto ): string => isset( $_GET[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $porDefecto;
		$hasta = min( $fecha( 'hasta', $hoy ), $hoy );
		$desde = $fecha( 'desde', ( new DateTimeImmutable( $hasta ) )->modify( '-6 days' )->format( 'Y-m-d' ) );
		$minimo = ( new DateTimeImmutable( $hasta ) )->modify( '-' . ( self::MAX_DIAS - 1 ) . ' days' )->format( 'Y-m-d' );
		$estado = isset( $_GET['estado'] ) ? sanitize_key( $_GET['estado'] ) : '';

		return array(
			'persona' => isset( $_GET['persona'] ) ? absint( $_GET['persona'] ) : 0,
			'desde'   => max( min( $desde, $hasta ), $minimo ),
			'hasta'   => $hasta,
			'estado'  => isset( self::ESTADOS[ $estado ] ) || 'problemas' === $estado ? $estado : '',
		);
		// phpcs:enable
	}

	public static function url( array $filtros = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + array_filter( $filtros ), admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$f        = self::filtros();
		$alcance  = PantallaHoy::alcance();
		$personas = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => ( null === $alcance || in_array( (int) $t['id'], $alcance, true ) ) && ( ! $f['persona'] || (int) $t['id'] === $f['persona'] ) ) );
		$filas    = self::filas( $personas, $f );
		$editar   = isset( $_GET['editar'] ) ? absint( $_GET['editar'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$diaEd    = isset( $_GET['dia'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['dia'] ) ? sanitize_text_field( wp_unslash( $_GET['dia'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$todos    = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => null === $alcance || in_array( (int) $t['id'], $alcance, true ) ) );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Registros y revisión</h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pk-fila espacio">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="pk-reg-persona">Persona</label>
				<select id="pk-reg-persona" name="persona">
					<option value="0">Persona: todas</option>
					<?php foreach ( $todos as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( $f['persona'], (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="pk-reg-desde">Del</label>
				<input type="date" id="pk-reg-desde" name="desde" value="<?php echo esc_attr( $f['desde'] ); ?>">
				<label for="pk-reg-hasta">al</label>
				<input type="date" id="pk-reg-hasta" name="hasta" value="<?php echo esc_attr( $f['hasta'] ); ?>">
				<label class="screen-reader-text" for="pk-reg-estado">Estado</label>
				<select id="pk-reg-estado" name="estado">
					<option value="">Estado: todos</option>
					<option value="problemas" <?php selected( $f['estado'], 'problemas' ); ?>>Solo con problemas</option>
					<?php foreach ( self::ESTADOS as $clave => [ $texto ] ) : ?>
						<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $f['estado'], $clave ); ?>><?php echo esc_html( $texto ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button">Filtrar</button>
				<?php if ( current_user_can( Capacidades::EXPORTAR ) ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaExportar::SLUG ) ); ?>">Exportar</a>
				<?php endif; ?>
			</form>

			<div class="pk-desplaza">
				<table class="widefat striped">
					<thead><tr><th>Fecha</th><th>Persona</th><th>Entrada</th><th>Comida</th><th>Salida</th><th>Horas</th><th>Estado</th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $filas ) : ?>
						<tr><td colspan="8">No hay registros con esos filtros.</td></tr>
					<?php endif; ?>
					<?php foreach ( $filas as $r ) : ?>
						<tr class="<?php echo $r['problema'] ? 'pk-fila-alerta' : ''; ?><?php echo $editar === $r['id'] && $diaEd === $r['dia'] ? ' pk-fila-elegida' : ''; ?>">
							<td><?php echo esc_html( wp_date( 'D j M', strtotime( $r['dia'] . ' 12:00:00' ) ) ); ?></td>
							<td><span class="pk-av"><?php echo esc_html( $r['iniciales'] ); ?></span><a href="<?php echo esc_url( PantallaFicha::url( $r['id'], 'asistencia' ) ); ?>"><?php echo esc_html( $r['nombre'] ); ?></a></td>
							<td><?php echo esc_html( $r['entrada'] ); ?></td>
							<td><?php echo esc_html( $r['comida'] ); ?></td>
							<td><?php echo esc_html( $r['salida'] ); ?><?php echo $r['otro_dia'] ? ' <span class="pk-chip info">día siguiente</span>' : ''; ?></td>
							<td><?php echo esc_html( $r['horas'] ); ?></td>
							<td>
								<?php foreach ( $r['estados'] as $e ) : ?>
									<span class="pk-chip <?php echo esc_attr( self::ESTADOS[ $e ][1] ); ?>"><?php echo esc_html( self::ESTADOS[ $e ][0] ); ?></span>
								<?php endforeach; ?>
							</td>
							<td>
								<?php if ( current_user_can( Capacidades::MARCA_MANUAL ) && ! Trabajadores::esPropio( $r['id'] ) ) : ?>
									<a class="button" href="<?php echo esc_url( self::url( $f + array( 'editar' => $r['id'], 'dia' => $r['dia'] ) ) ); ?>">Editar</a>
								<?php endif; ?>
								<?php if ( in_array( 'falta', $r['estados'], true ) && current_user_can( Capacidades::JUSTIFICAR ) ) : ?>
									<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => PantallaAusencias::SLUG, 'trabajador' => $r['id'], 'tipo' => 'falta', 'desde' => $r['dia'] ), admin_url( 'admin.php' ) ) ); ?>">Justificar falta</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="description">Los días con problemas aparecen primero. "Pasó de medianoche" marca una jornada que terminó al día siguiente: no se corta a las 23:59 y sus horas cuentan en el día en que empezó. "Fuera de reglas" marca, por ejemplo, un día laborable sin la comida marcada (se descontó la comida). "Editado": tiene marcas corregidas por gerencia.</p>

			<?php
			if ( $editar && '' !== $diaEd && current_user_can( Capacidades::MARCA_MANUAL ) ) {
				self::panel( $editar, $diaEd, $f, $alcance );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $personas
	 * @return array<int, array<string, mixed>>
	 */
	private static function filas( array $personas, array $f ): array {
		global $wpdb;

		if ( ! $personas ) {
			return array();
		}

		$zona     = Ajustes::zona();
		$hoy      = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$ids      = array_map( static fn( array $t ): int => (int) $t['id'], $personas );
		$jornadas = Jornadas::delRango( $personas, $f['desde'], $f['hasta'] );
		$marcas   = Marcas::delRango( $ids, $f['desde'], $f['hasta'] );
		$hora     = static fn( ?Marca $m ): string => $m ? $m->marcadoUtc->setTimezone( $zona )->format( 'H:i' ) : '—';
		$editados = array();
		$revisar  = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT trabajador_id, dia_laboral FROM ' . Tablas::nombre( 'marcas' ) . " WHERE origen <> 'trabajador' AND dia_laboral BETWEEN %s AND %s AND trabajador_id IN (" . implode( ',', $ids ) . ')', $f['desde'], $f['hasta'] ), ARRAY_A ) as $e ) {
			$editados[ $e['trabajador_id'] . '|' . $e['dia_laboral'] ] = true;
		}

		foreach ( Revisiones::pendientes( $ids ) as $m ) {
			$revisar[ $m['trabajador_id'] . '|' . $m['dia_laboral'] ] = true;
		}

		$filas = array();

		foreach ( $personas as $t ) {
			$id = (int) $t['id'];

			foreach ( $jornadas[ $id ] ?? array() as $dia => $j ) {
				$delDia = $marcas[ $id ][ $dia ] ?? array();

				// Sin marcas: solo interesa si es falta.
				if ( ! $delDia && CalculoJornada::FALTA !== $j->estado ) {
					continue;
				}

				$pasos   = PasosDelDia::de( $delDia );
				$salida  = $pasos[ PasosDelDia::SALIDA ];
				$otroDia = $salida && $salida->marcadoUtc->setTimezone( $zona )->format( 'Y-m-d' ) !== $dia;
				$clave   = $id . '|' . $dia;
				$estados = array();

				if ( CalculoJornada::FALTA === $j->estado ) {
					$estados[] = 'falta';
				} elseif ( CalculoJornada::INCOMPLETO === $j->estado ) {
					$estados[] = $dia === $hoy ? 'en_curso' : 'incompleto';
				}

				if ( isset( $revisar[ $clave ] ) || CalculoJornada::A_REVISAR === $j->estado ) {
					$estados[] = 'revisar';
				}

				if ( $j->minutosComidaDescontada > 0 ) {
					$estados[] = 'fuera';
				}

				if ( $otroDia ) {
					$estados[] = 'medianoche';
				}

				if ( isset( $editados[ $clave ] ) ) {
					$estados[] = 'editado';
				}

				if ( ! $estados ) {
					$estados[] = 'normal';
				}

				$problema = (bool) array_intersect( $estados, array( 'incompleto', 'falta', 'revisar', 'fuera' ) );

				if ( 'problemas' === $f['estado'] && ! $problema || '' !== $f['estado'] && 'problemas' !== $f['estado'] && ! in_array( $f['estado'], $estados, true ) ) {
					continue;
				}

				$filas[] = array(
					'id'        => $id,
					'dia'       => $dia,
					'nombre'    => Trabajadores::nombreCompleto( $t ),
					'iniciales' => mb_strtoupper( mb_substr( (string) $t['nombres'], 0, 1 ) . mb_substr( (string) $t['apellidos'], 0, 1 ) ),
					'entrada'   => $hora( $pasos[ PasosDelDia::ENTRADA ] ),
					'comida'    => $pasos[ PasosDelDia::INICIO_COMIDA ] ? $hora( $pasos[ PasosDelDia::INICIO_COMIDA ] ) . '–' . ( $pasos[ PasosDelDia::FIN_COMIDA ] ? $hora( $pasos[ PasosDelDia::FIN_COMIDA ] ) : '…' ) : '—',
					'salida'    => $hora( $salida ),
					'otro_dia'  => $otroDia,
					'horas'     => $j->minutosTrabajados ? VistaDia::duracion( $j->minutosTrabajados ) : '—',
					'estados'   => $estados,
					'problema'  => $problema,
					'orden'     => min( array_map( static fn( string $e ): int => self::ESTADOS[ $e ][2], $estados ) ),
				);
			}
		}

		// Primero lo urgente; dentro de cada grupo, lo más reciente.
		usort( $filas, static fn( $a, $b ): int => array( $a['orden'], $b['dia'], $a['nombre'] ) <=> array( $b['orden'], $a['dia'], $b['nombre'] ) );

		return $filas;
	}

	/** Panel lateral para editar un día (G4B). */
	private static function panel( int $id, string $dia, array $f, ?array $alcance ): void {
		$t = Trabajadores::buscar( $id );

		if ( ! $t || ( null !== $alcance && ! in_array( $id, $alcance, true ) ) || Trabajadores::esPropio( $id ) ) {
			return;
		}

		$zona   = Ajustes::zona();
		$marcas = Marcas::delDia( $id, $dia );
		$pasos  = PasosDelDia::de( $marcas );
		$otras  = PasosDelDia::otras( $marcas );
		$hora   = static fn( ?Marca $m ): string => $m ? $m->marcadoUtc->setTimezone( $zona )->format( 'H:i' ) : '';
		?>
		<aside class="pk-panel" aria-labelledby="pk-panel-titulo">
			<h2 id="pk-panel-titulo">Editar registro</h2>
			<p class="description"><?php echo esc_html( Trabajadores::nombreCompleto( $t ) . ' · ' . wp_date( 'l j \d\e F', strtotime( $dia . ' 12:00:00' ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_editar_dia">
				<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="dia" value="<?php echo esc_attr( $dia ); ?>">
				<input type="hidden" name="volver" value="<?php echo esc_url( self::url( $f ) ); ?>">
				<?php wp_nonce_field( 'pka_editar_dia' ); ?>
				<?php foreach ( PasosDelDia::NOMBRES as $paso => $nombre ) : ?>
					<?php $antes = $hora( $pasos[ $paso ] ); ?>
					<p class="pk-campo">
						<label for="pk-ed-<?php echo esc_attr( $paso ); ?>"><?php echo esc_html( $nombre ); ?></label>
						<input type="time" id="pk-ed-<?php echo esc_attr( $paso ); ?>" name="horas[<?php echo esc_attr( $paso ); ?>]" value="<?php echo esc_attr( $antes ); ?>" data-antes="<?php echo esc_attr( $antes ); ?>">
						<span class="pk-antes">Antes: <?php echo esc_html( '' !== $antes ? $antes : 'sin marca' ); ?></span>
					</p>
				<?php endforeach; ?>
				<?php if ( $otras ) : ?>
					<p class="description">Este día también tiene <?php echo count( $otras ); ?> marcas de salidas temporales (encargo, salud…). Para cambiarlas, <a href="<?php echo esc_url( PantallaDia::url( $id, $dia ) ); ?>">abre el día completo</a>.</p>
				<?php endif; ?>
				<p class="description">Deja una hora vacía para quitar esa marca. Una hora menor que la de entrada se toma del día siguiente.</p>
				<p class="pk-campo">
					<label for="pk-ed-motivo">Motivo del cambio <em>(obligatorio)</em></label>
					<textarea id="pk-ed-motivo" name="motivo" rows="3" required></textarea>
				</p>
				<p class="pk-nota-rosa"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Este cambio quedará en la auditoría: quién, cuándo, valor anterior y motivo.</p>
				<p class="pk-fila">
					<button class="button button-primary">Guardar cambio</button>
					<a class="button" href="<?php echo esc_url( self::url( $f ) ); ?>">Cancelar</a>
				</p>
			</form>
		</aside>
		<?php
	}

	public static function guardar(): void {
		if ( ! current_user_can( Capacidades::MARCA_MANUAL ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_editar_dia' );

		$id      = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$dia     = isset( $_POST['dia'] ) ? sanitize_text_field( wp_unslash( $_POST['dia'] ) ) : '';
		$horas   = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['horas'] ?? array() ) );
		$motivo  = isset( $_POST['motivo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$volver  = isset( $_POST['volver'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['volver'] ) ), self::url() ) : self::url();
		$alcance = PantallaHoy::alcance();

		if ( null !== $alcance && ! in_array( $id, $alcance, true ) ) {
			$r = new \WP_Error( 'pka_alcance', 'Esa persona no es de tu equipo.' );
		} elseif ( Trabajadores::esPropio( $id ) ) {
			$r = new \WP_Error( 'pka_propio', 'No puedes editar tus propias marcas: lo hace otra persona con permiso.' );
		} else {
			$r = Correcciones::editarDia( $id, $dia, array_intersect_key( $horas, PasosDelDia::NOMBRES ), $motivo );
		}

		if ( is_wp_error( $r ) ) {
			Avisos::agregar( 'error', esc_html( $r->get_error_message() ) );
			Menu::volverA( add_query_arg( array( 'editar' => $id, 'dia' => $dia ), $volver ) );
		}

		Avisos::agregar( 'success', 'Cambio guardado. Quedó en la auditoría.' );
		Menu::volverA( $volver );
	}
}
