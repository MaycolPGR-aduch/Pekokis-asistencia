<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\HorasAjustes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Sueldos;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Pagos → Contabilidad de horas (pedido de gerencia, 0.22.0). Solo gerencia.
 *
 * - Sin persona: el mes de todos (esperadas, trabajadas, extra, sábado,
 *   domingo y feriado, madrugada, ajustes y semanas completas).
 * - Con persona: día por día agrupado por semana, si completó la semana (de
 *   eso depende el sábado), la base de 192 h de su sueldo, y el ajuste de
 *   horas de un día con motivo (suma o resta; lo que pasa del horario es extra).
 */
final class PantallaHoras {
	public const SLUG = 'pka-horas';

	public static function registrar(): void {
		add_action( 'admin_post_pka_ajustar_horas', array( self::class, 'ajustar' ) );
		add_action( 'admin_post_pka_anular_ajuste_horas', array( self::class, 'anular' ) );
	}

	/** @param array<string, scalar> $args */
	public static function url( string $mes = '', int $trabajadorId = 0, array $args = array() ): string {
		return add_query_arg( array_filter( array( 'page' => self::SLUG, 'mes' => $mes, 't' => $trabajadorId ) ) + $args, admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::JUSTIFICAR ) ) {
			wp_die( 'Esta página es solo de gerencia.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$meses = Exportes::meses();
		$mes   = isset( $_GET['mes'] ) && in_array( $_GET['mes'], $meses, true ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : $meses[0];
		$tid   = isset( $_GET['t'] ) ? absint( $_GET['t'] ) : 0;
		// phpcs:enable

		$area     = FiltroArea::actual();
		$personas = FiltroArea::aplicar( Informes::personas( $mes, null ), $area );
		$persona  = $tid ? ( array_values( array_filter( $personas, static fn( array $t ): bool => (int) $t['id'] === $tid ) )[0] ?? null ) : null;
		?>
		<div class="wrap pk-horas">
			<?php Avisos::mostrar(); ?>
			<h1>Contabilidad de horas</h1>
			<p class="description">Las horas del mes de cada persona según su horario y su sueldo (base de <?php echo (int) Sueldo::HORAS_MES; ?> h al mes). Solo la ve gerencia. Cuentan los días que ya terminaron.</p>

			<form method="get" class="pk-fila pk-mt-s">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="pk-h-mes">Mes</label>
				<select id="pk-h-mes" name="mes" onchange="this.form.submit()">
					<?php foreach ( $meses as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $mes, $m ); ?>><?php echo esc_html( ucfirst( PantallaResumen::nombreMes( $m ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="pk-h-t">Persona</label>
				<select id="pk-h-t" name="t" onchange="this.form.submit()">
					<option value="">Todo el equipo</option>
					<?php foreach ( $personas as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tid, (int) $t['id'] ); ?>><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $persona ) : ?>
					<?php FiltroArea::selector( $area, 'pk-h-area' ); ?>
				<?php endif; ?>
				<noscript><button class="button">Ver</button></noscript>
			</form>

			<?php
			if ( $persona ) {
				self::persona( $persona, $mes );
			} else {
				self::equipo( $personas, $mes );
			}
			?>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $personas */
	private static function equipo( array $personas, string $mes ): void {
		$datos = Jornadas::delMes( $personas, $mes );
		$h     = static fn( int $m ): string => PrePlanilla::horas( $m );
		?>
		<div class="pk-desplaza pk-mt-s">
			<table class="widefat striped pk-tabla-horas">
				<thead><tr><th>Persona</th><th>Esperadas</th><th>Trabajadas</th><th>Faltaron</th><th>Extra</th><th>Sábado</th><th>Dom. y fer.</th><th>Madrugada</th><th>Ajustes</th><th>Semanas completas</th></tr></thead>
				<tbody>
				<?php if ( ! $personas ) : ?>
					<tr><td colspan="10">No hay personas con días en este mes.</td></tr>
				<?php endif; ?>
				<?php foreach ( $personas as $t ) : ?>
					<?php
					$id   = (int) $t['id'];
					$fila = PrePlanilla::calcular( $datos['jornadas'][ $id ] ?? array(), array(), null, $datos['semanas'][ $id ] ?? array() );
					[ $completas, $conSabado ] = self::semanasConSabado( $datos['jornadas'][ $id ] ?? array(), $datos['semanas'][ $id ] ?? array() );
					?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( $mes, $id ) ); ?>"><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></a></td>
						<td><?php echo esc_html( $h( $fila->minutos['esperados'] ) ); ?></td>
						<td><strong><?php echo esc_html( $h( $fila->minutos['trabajados'] ) ); ?></strong></td>
						<td><?php echo $fila->minutos['no_trabajados'] ? '<span class="pk-txt-aviso">' . esc_html( $h( $fila->minutos['no_trabajados'] ) ) . '</span>' : '—'; ?></td>
						<td><?php echo esc_html( $h( $fila->minutos['extra'] ) ); ?></td>
						<td><?php echo esc_html( $h( $fila->minutos['sabado'] ) ); ?></td>
						<td><?php echo esc_html( $h( $fila->minutos['domingo'] + $fila->minutos['feriado'] ) ); ?></td>
						<td><?php echo esc_html( $h( $fila->minutos['madrugada'] ) ); ?></td>
						<td><?php echo $fila->minutos['ajuste'] ? esc_html( HorasAjustes::texto( $fila->minutos['ajuste'] ) ) : '—'; ?></td>
						<td><?php echo $conSabado ? esc_html( $completas . ' de ' . $conSabado ) . ' <small class="description">con sábado trabajado</small>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description">Toca un nombre para ver su mes día por día y ajustar las horas de un día.</p>
		<?php
	}

	/** @param array<string, mixed> $t */
	private static function persona( array $t, string $mes ): void {
		$id       = (int) $t['id'];
		$datos    = Jornadas::delMes( array( $t ), $mes );
		$jornadas = $datos['jornadas'][ $id ] ?? array();
		$semanas  = $datos['semanas'][ $id ] ?? array();
		$sueldo   = current_user_can( Capacidades::VER_SUELDOS ) ? Sueldos::vigente( $id, $datos['hasta'] ) : null;
		$fila     = PrePlanilla::calcular( $jornadas, $sueldo ? array( $sueldo ) : array(), \Pekokis\Asistencia\WordPress\Ajustes::reglasPago(), $semanas );
		$ajustes  = HorasAjustes::deTrabajador( $id, $datos['desde'], $datos['hasta'] );
		$h        = static fn( int $m ): string => PrePlanilla::horas( $m );
		$puede    = ! Trabajadores::esPropio( $id );
		$diaPedido = isset( $_GET['dia'] ) ? sanitize_text_field( wp_unslash( $_GET['dia'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$porSemana = array();

		foreach ( $jornadas as $dia => $j ) {
			$porSemana[ PrePlanilla::lunes( (string) $dia ) ][ (string) $dia ] = $j;
		}
		?>
		<h2 class="pk-mt"><?php echo esc_html( Trabajadores::nombreCompleto( $t ) . ' · ' . PantallaResumen::nombreMes( $mes ) ); ?> <a class="pk-chico" href="<?php echo esc_url( PantallaFicha::url( $id ) ); ?>">Ver ficha</a></h2>
		<div class="pk-kpis">
			<div class="pk-kpi"><span>Base de su sueldo</span><b><?php echo (int) Sueldo::HORAS_MES; ?> h</b><small><?php echo $sueldo ? esc_html( 'Valor hora ' . Sueldo::soles( $sueldo->valorHora() ) . ( $sueldo->porHoras() ? ' · cobra por horas' : '' ) ) : 'Sin honorario registrado'; ?></small></div>
			<div class="pk-kpi"><span>Esperadas por su horario</span><b><?php echo esc_html( $h( $fila->minutos['esperados'] ) ); ?></b><small><?php echo esc_html( $fila->dias['laborables'] . ' días laborables' ); ?></small></div>
			<div class="pk-kpi"><span>Trabajadas</span><b><?php echo esc_html( $h( $fila->minutos['trabajados'] ) ); ?></b><small><?php echo esc_html( round( 100 * $fila->minutos['trabajados'] / ( Sueldo::HORAS_MES * 60 ) ) . ' % de las ' . Sueldo::HORAS_MES . ' h' ); ?></small></div>
			<div class="pk-kpi"><span>Extra, sábado, domingo y feriado</span><b><?php echo esc_html( $h( $fila->minutos['extra'] + $fila->minutos['sabado'] + $fila->minutos['domingo'] + $fila->minutos['feriado'] ) ); ?></b><small><?php echo esc_html( 'extra ' . $h( $fila->minutos['extra'] ) . ' · sáb ' . $h( $fila->minutos['sabado'] ) ); ?></small></div>
			<div class="pk-kpi"><span>Madrugada</span><b><?php echo esc_html( $h( $fila->minutos['madrugada'] ) ); ?></b><small>Hasta las 6:00</small></div>
			<div class="pk-kpi"><span>Faltaron</span><b><?php echo esc_html( $h( $fila->minutos['no_trabajados'] ) ); ?></b><small>Se informa, no se descuenta</small></div>
		</div>

		<?php if ( ! $jornadas ) : ?>
			<p class="pk-mt-s">Este mes todavía no tiene días terminados.</p>
		<?php endif; ?>

		<?php foreach ( $porSemana as $lunes => $dias ) : ?>
			<?php
			$s        = $semanas[ $lunes ] ?? array( 'esperados' => 0, 'trabajados' => 0, 'completa' => false );
			$sabadoT  = array_sum( array_map( static fn( CalculoJornada $j ): int => $j->minutosSabado, $dias ) );
			?>
			<h3 class="pk-mt-s">Semana del <?php echo esc_html( wp_date( 'j \d\e F', strtotime( $lunes . ' 12:00' ) ) ); ?></h3>
			<div class="pk-desplaza">
				<table class="widefat striped pk-tabla-horas">
					<thead><tr><th>Día</th><th>Estado</th><th>Esperadas</th><th>Trabajadas</th><th>Antes de entrar</th><th>Madrugada</th><th>Extra</th><th>Sáb. / dom. / fer.</th><th>Ajuste</th><th>Faltó</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $dias as $dia => $j ) : ?>
						<tr>
							<td class="pk-nowrap"><a href="<?php echo esc_url( PantallaDia::url( $id, $dia ) ); ?>"><?php echo esc_html( wp_date( 'D j', strtotime( $dia . ' 12:00' ) ) ); ?></a></td>
							<td><?php echo esc_html( CalculoJornada::ESTADOS[ $j->estado ] ?? $j->estado ); ?></td>
							<td><?php echo $j->minutosEsperados ? esc_html( $h( $j->minutosEsperados ) ) : '—'; ?></td>
							<td><strong><?php echo $j->minutosTrabajados ? esc_html( $h( $j->minutosTrabajados ) ) : '—'; ?></strong></td>
							<td><?php echo $j->minutosTemprano ? esc_html( $h( $j->minutosTemprano ) ) : '—'; ?></td>
							<td><?php echo $j->minutosMadrugada ? esc_html( $h( $j->minutosMadrugada ) ) : '—'; ?></td>
							<td><?php echo $j->minutosExtra ? esc_html( $h( $j->minutosExtra ) ) : '—'; ?></td>
							<td><?php echo $j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado ? esc_html( $h( $j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado ) ) : '—'; ?></td>
							<td><?php echo $j->minutosAjuste ? esc_html( HorasAjustes::texto( $j->minutosAjuste ) ) : '—'; ?></td>
							<td><?php echo $j->minutosNoTrabajados ? '<span class="pk-txt-aviso">' . esc_html( $h( $j->minutosNoTrabajados ) ) . '</span>' : '—'; ?></td>
							<td><?php if ( $puede ) : ?><a href="<?php echo esc_url( self::url( $mes, $id, array( 'dia' => $dia ) ) . '#ajustar' ); ?>">Ajustar</a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="description">
				De lunes a viernes trabajó <?php echo esc_html( $h( $s['trabajados'] ) ); ?> de <?php echo esc_html( $h( $s['esperados'] ) ); ?> (sin contar madrugada; los días justificados y los feriados cuentan como cumplidos):
				<?php if ( $s['completa'] ) : ?>
					<span class="pk-chip ok">Semana completa</span><?php echo $sabadoT ? ' el sábado va con la tarifa extra.' : ''; ?>
				<?php else : ?>
					<span class="pk-chip aviso">Semana incompleta</span><?php echo $sabadoT ? ' el sábado va a valor hora.' : ''; ?>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>

		<h2 class="pk-mt" id="ajustar">Ajustes de horas del mes</h2>
		<p class="description">Para sumar o restar minutos a un día (por ejemplo, trabajó fuera del taller o se olvidó de marcar la salida a tiempo). Lo sumado completa primero su horario; lo que pasa de su horario es hora extra. Cada ajuste queda en la auditoría.</p>
		<div class="pk-desplaza">
			<table class="widefat striped">
				<thead><tr><th>Día</th><th>Ajuste</th><th>Motivo</th><th>Registró</th><th>Estado</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $ajustes ) : ?>
					<tr><td colspan="6">Sin ajustes este mes.</td></tr>
				<?php endif; ?>
				<?php foreach ( $ajustes as $a ) : ?>
					<?php $quien = get_userdata( (int) $a['creado_por'] ); ?>
					<tr<?php echo 'anulado' === $a['estado'] ? ' class="pk-atenuado"' : ''; ?>>
						<td class="pk-nowrap"><?php echo esc_html( wp_date( 'D j M', strtotime( $a['dia'] . ' 12:00' ) ) ); ?></td>
						<td><strong><?php echo esc_html( HorasAjustes::texto( (int) $a['minutos'] ) ); ?></strong></td>
						<td><?php echo esc_html( $a['motivo'] ); ?><?php echo 'anulado' === $a['estado'] ? '<br><em>Anulado: ' . esc_html( $a['motivo_anulacion'] ) . '</em>' : ''; ?></td>
						<td><?php echo esc_html( $quien ? $quien->display_name : '—' ); ?></td>
						<td><span class="pk-chip <?php echo 'anulado' === $a['estado'] ? 'gris' : 'ok'; ?>"><?php echo 'anulado' === $a['estado'] ? 'Anulado' : 'Vigente'; ?></span></td>
						<td>
							<?php if ( 'vigente' === $a['estado'] && $puede ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
									<input type="hidden" name="action" value="pka_anular_ajuste_horas">
									<input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
									<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
									<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
									<?php wp_nonce_field( 'pka_anular_ajuste_horas' ); ?>
									<label class="screen-reader-text" for="pka-aa-<?php echo (int) $a['id']; ?>">Motivo de la anulación</label>
									<input type="text" id="pka-aa-<?php echo (int) $a['id']; ?>" name="motivo" required placeholder="Por qué se anula">
									<button class="button">Anular</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( ! $puede ) : ?>
			<p><em>Son tus propias horas: las ajusta otra persona de gerencia.</em></p>
		<?php elseif ( $jornadas ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-caja pk-mt-s pk-max-s">
				<h3>Ajustar las horas de un día</h3>
				<input type="hidden" name="action" value="pka_ajustar_horas">
				<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
				<?php wp_nonce_field( 'pka_ajustar_horas' ); ?>
				<p class="pk-campo"><label for="pka-aj-dia">Día</label>
					<input type="date" id="pka-aj-dia" name="dia" required min="<?php echo esc_attr( $datos['desde'] ); ?>" max="<?php echo esc_attr( $datos['hasta'] ); ?>" value="<?php echo esc_attr( isset( $jornadas[ $diaPedido ] ) ? $diaPedido : '' ); ?>"></p>
				<p class="pk-campo"><label for="pka-aj-min">Horas a sumar o restar</label>
					<input type="text" id="pka-aj-min" name="minutos" required placeholder="1:30 o -0:45" autocomplete="off">
					<small class="description">Con un signo menos para restar. Hasta 16 h.</small></p>
				<p class="pk-campo"><label for="pka-aj-motivo">Motivo</label>
					<input type="text" id="pka-aj-motivo" name="motivo" class="regular-text" required maxlength="255" placeholder="Por ejemplo: entrega fuera del taller"></p>
				<p><button class="button button-primary">Guardar ajuste</button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Semanas del mes con sábado trabajado, y cuántas de ellas están completas.
	 *
	 * @param array<string, CalculoJornada>                                         $jornadas
	 * @param array<string, array{esperados: int, trabajados: int, completa: bool}> $semanas
	 * @return array{0: int, 1: int}
	 */
	private static function semanasConSabado( array $jornadas, array $semanas ): array {
		$completas = 0;
		$con       = 0;

		foreach ( $jornadas as $dia => $j ) {
			if ( $j->minutosSabado > 0 ) {
				++$con;
				$completas += ( $semanas[ PrePlanilla::lunes( (string) $dia ) ]['completa'] ?? false ) ? 1 : 0;
			}
		}

		return array( $completas, $con );
	}

	public static function ajustar(): void {
		check_admin_referer( 'pka_ajustar_horas' );

		$id      = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$mes     = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$dia     = isset( $_POST['dia'] ) ? sanitize_text_field( wp_unslash( $_POST['dia'] ) ) : '';
		$motivo  = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$minutos = HorasAjustes::aMinutos( isset( $_POST['minutos'] ) ? sanitize_text_field( wp_unslash( $_POST['minutos'] ) ) : '' );
		$r       = null === $minutos ? new WP_Error( 'pka_minutos', 'Escribe las horas como 1:30, 2 o -0:45.' ) : HorasAjustes::crear( $id, $dia, $minutos, $motivo );

		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : 'Ajuste guardado: ' . HorasAjustes::texto( (int) $minutos ) . ' el ' . $dia . '.' ) );
		Menu::volverA( self::url( $mes, $id ) . '#ajustar' );
	}

	public static function anular(): void {
		check_admin_referer( 'pka_anular_ajuste_horas' );

		$id     = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$mes    = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$r      = HorasAjustes::anular( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, $motivo );

		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : 'Ajuste anulado.' ) );
		Menu::volverA( self::url( $mes, $id ) . '#ajustar' );
	}
}
