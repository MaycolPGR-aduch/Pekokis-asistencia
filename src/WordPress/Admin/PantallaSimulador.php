<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\SimulacionPago;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Sueldos;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Pagos → Simulador de pagos: probar otros honorarios, tarifas y horas sin
 * guardar nada. Tres pestañas:
 *
 * - "¿Y si…?": una persona y un mes con sus horas reales; se cambian los valores
 *   y se ve lo real, lo simulado y la diferencia.
 * - "Todo el equipo": subir honorarios o tarifas, o sumar horas, a todos.
 * - "Desde cero": honorario, tarifas y horas escritos a mano.
 *
 * Calcula con SimulacionPago (la misma fórmula de la planilla). La pantalla se
 * actualiza sola (admin.js pide la misma URL con parcial=1 y cambia los resultados).
 */
final class PantallaSimulador {
	public const SLUG = 'pka-simulador';

	private const PESTANAS = array(
		'persona' => '¿Y si…?',
		'equipo'  => 'Todo el equipo',
		'libre'   => 'Desde cero',
	);

	private const NOMBRES = array(
		'honorario'     => 'Honorario',
		'horas'         => 'Pago por horas',
		'extra'         => 'Horas extra',
		'sabado_extra'  => 'Sábado con semana completa',
		'sabado_normal' => 'Sábado sin completar la semana',
		'domingo'       => 'Domingo',
		'feriado'       => 'Feriado',
		'madrugada'     => 'Madrugada',
		'descuentos'    => 'Descuentos',
		'total'         => 'Total del mes',
	);

	/** Filas que solo se muestran si tienen algo. */
	private const OPCIONALES = array( 'horas', 'descuentos' );

	public static function registrar(): void {
		add_action( 'admin_post_pka_simulador_excel', array( self::class, 'excel' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$e = self::entrada( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura, no guarda nada.
		$r = self::calcular( $e );

		if ( empty( $_GET['parcial'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::registrarUso();
		}

		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Simulador de pagos <span class="pk-h1-der">No guarda nada</span></h1>

			<nav class="pk-pestanas pk-mt-s" aria-label="Formas de simular">
				<?php foreach ( self::PESTANAS as $clave => $titulo ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'pestana' => $clave, 'mes' => $e['mes'] ) ) ); ?>" class="<?php echo $clave === $e['pestana'] ? 'activa' : ''; ?>" <?php echo $clave === $e['pestana'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $titulo ); ?></a>
				<?php endforeach; ?>
			</nav>

			<p class="description pk-mt-s"><?php echo esc_html( self::ayuda( $e['pestana'] ) ); ?></p>

			<form method="get" class="pk-simulador" id="pk-simulador" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="pestana" value="<?php echo esc_attr( $e['pestana'] ); ?>">
				<div class="pk-dos">
					<div class="pk-caja">
						<?php
						match ( $e['pestana'] ) {
							'equipo' => self::camposEquipo( $e ),
							'libre'  => self::camposLibre( $e ),
							default  => self::camposPersona( $e, $r ),
						};
						?>
						<p class="pk-fila espacio pk-mt-s">
							<button class="button button-primary pk-sim-calcular">Calcular</button>
							<a class="button-link" href="<?php echo esc_url( self::url( array( 'pestana' => $e['pestana'], 'mes' => $e['mes'], 't' => $e['t'] ) ) ); ?>">Volver a los valores reales</a>
						</p>
					</div>
					<div id="pk-sim-resultado" aria-live="polite">
						<?php self::resultado( $e, $r ); ?>
					</div>
				</div>
			</form>

			<p class="pk-nota-rosa"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> Esto es una simulación: no cambia honorarios, tarifas ni pagos. Para aplicar un cambio, ve a <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaSueldos::SLUG ) ); ?>">Sueldos y tarifas</a>.</p>
		</div>
		<?php
	}

	/** Excel de la simulación que se está viendo. */
	public static function excel(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_simulador_excel' );

		$e     = self::entrada( $_GET );
		$r     = self::calcular( $e );
		$soles = static fn( int $c ): float => round( $c / 100, 2 );
		$horas = static fn( int $m ): float => round( $m / 60, 2 );
		$hoja  = array( array( '<b>Simulación de pagos · ' . self::PESTANAS[ $e['pestana'] ] . ' · ' . $e['mes'] . '</b>' ), array( 'Es una simulación: no cambia honorarios, tarifas ni pagos.' ), array() );

		if ( 'equipo' === $e['pestana'] ) {
			$hoja[] = array( '<b>Persona</b>', '<b>Real (S/)</b>', '<b>Simulado (S/)</b>', '<b>Diferencia (S/)</b>' );
			foreach ( $r['filas'] as $f ) {
				$hoja[] = array( $f['nombre'], null === $f['real'] ? 'Falta su honorario' : $soles( $f['real']['montos']['total'] ), null === $f['simulado'] ? '—' : $soles( $f['simulado']['montos']['total'] ), null === $f['real'] ? '—' : $soles( $f['simulado']['montos']['total'] - $f['real']['montos']['total'] ) );
			}
			$hoja[] = array( '<b>Total</b>', $soles( $r['totalReal'] ), $soles( $r['totalSimulado'] ), $soles( $r['totalSimulado'] - $r['totalReal'] ) );
		} else {
			$hoja[] = array( '<b>Concepto</b>', '<b>Horas</b>' ) + ( $r['real'] ? array( 2 => '<b>Real (S/)</b>', 3 => '<b>Simulado (S/)</b>', 4 => '<b>Diferencia (S/)</b>' ) : array( 2 => '<b>Monto (S/)</b>' ) );
			foreach ( self::NOMBRES as $clave => $nombre ) {
				if ( ! self::mostrar( $clave, $r ) ) {
					continue;
				}
				$fila = array( $nombre, in_array( $clave, SimulacionPago::TIPOS, true ) ? $horas( $r['simulado']['minutos'][ $clave ] ) : '' );
				if ( $r['real'] ) {
					$fila[] = $soles( $r['real']['montos'][ $clave ] );
				}
				$fila[] = $soles( $r['simulado']['montos'][ $clave ] );
				if ( $r['real'] ) {
					$fila[] = $soles( $r['simulado']['montos'][ $clave ] - $r['real']['montos'][ $clave ] );
				}
				$hoja[] = $fila;
			}
		}

		$hoja[] = array();
		$hoja[] = array( 'Generado por ' . wp_get_current_user()->display_name . ' el ' . wp_date( 'Y-m-d H:i' ) );

		Auditoria::registrar( 'simulador.descargar', 'registro', null, null, array( 'mes' => $e['mes'], 'formato' => 'Excel', 'modo' => self::PESTANAS[ $e['pestana'] ] ) );

		$contenido = (string) SimpleXLSXGen::fromArray( $hoja, 'Simulación' )->setTitle( 'Simulación de pagos ' . $e['mes'] );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="simulacion-pagos-' . $e['pestana'] . '-' . $e['mes'] . '.xlsx"' );
		header( 'Content-Length: ' . strlen( $contenido ) );
		echo $contenido; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- archivo binario.
		exit;
	}

	/**
	 * Valores pedidos, ya limpios. Montos en céntimos y horas en minutos; los
	 * campos vacíos quedan en null ("usar el valor real").
	 *
	 * @param array<string, mixed> $origen
	 * @return array<string, mixed>
	 */
	public static function entrada( array $origen ): array {
		$texto  = static fn( string $k ): string => isset( $origen[ $k ] ) ? trim( sanitize_text_field( wp_unslash( (string) $origen[ $k ] ) ) ) : '';
		$meses  = Exportes::meses();
		$mes    = in_array( $texto( 'mes' ), $meses, true ) ? $texto( 'mes' ) : ( $meses[0] ?? wp_date( 'Y-m' ) );
		$pest   = isset( self::PESTANAS[ $texto( 'pestana' ) ] ) ? $texto( 'pestana' ) : 'persona';
		$e      = array( 'pestana' => $pest, 'mes' => $mes, 't' => absint( $texto( 't' ) ), 'crudo' => array() );

		foreach ( array_merge( array( 'honorario' ), array_keys( Sueldo::TARIFAS ) ) as $k ) {
			$e['dinero'][ $k ]      = self::dinero( $texto( $k ) );
			$e['crudo'][ $k ]       = $texto( $k );
		}

		foreach ( SimulacionPago::TIPOS as $k ) {
			$e['horas'][ $k ]          = self::minutos( $texto( 'h_' . $k ) );
			$e['crudo'][ 'h_' . $k ]   = $texto( 'h_' . $k );
		}

		return $e;
	}

	/**
	 * @param array<string, mixed> $e
	 * @return array<string, mixed>
	 */
	public static function calcular( array $e ): array {
		$personas = Informes::personas( $e['mes'], null );
		$desde    = $e['mes'] . '-01';
		$hasta    = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), Jornadas::ayer() );

		$reglas = \Pekokis\Asistencia\WordPress\Ajustes::reglasPago();

		if ( 'libre' === $e['pestana'] ) {
			$tarifas = array();
			foreach ( array_keys( Sueldo::TARIFAS ) as $k ) {
				$tarifas[ $k ] = (int) ( $e['dinero'][ $k ] ?? 0 );
			}

			return array( 'real' => null, 'simulado' => SimulacionPago::libre( (int) ( $e['dinero']['honorario'] ?? 0 ), $tarifas, array_map( 'intval', $e['horas'] ), $reglas->multiplicadorMadrugada ) );
		}

		$mesD     = Jornadas::delMes( $personas, $e['mes'] );
		$jornadas = $mesD['jornadas'];
		$sueldos  = Sueldos::deTrabajadores( array_map( static fn( array $t ): int => (int) $t['id'], $personas ) );
		$conMes   = static fn( int $id, int $honorario, array $tarifas, ?Sueldo $base ): array => SimulacionPago::conJornadas( $jornadas[ $id ] ?? array(), $honorario, $tarifas, array_map( 'intval', $e['horas'] ), $reglas, $mesD['semanas'][ $id ] ?? array(), $mesD['descuentos'][ $id ] ?? 0, $base ? $base->modo : Sueldo::FIJO, $base ? $base->aparte : array() );
		$real     = static fn( int $id ): PrePlanilla => PrePlanilla::calcular( $jornadas[ $id ] ?? array(), $sueldos[ $id ] ?? array(), $reglas, $mesD['semanas'][ $id ] ?? array(), $mesD['descuentos'][ $id ] ?? 0 );

		if ( 'equipo' === $e['pestana'] ) {
			$filas = array();
			$tReal = 0;
			$tSim  = 0;

			foreach ( $personas as $t ) {
				$id   = (int) $t['id'];
				$filaR = $real( $id );
				$base  = $filaR->sueldoFinal ?? Sueldo::vigenteEn( $sueldos[ $id ] ?? array(), $hasta );

				if ( null === $base ) {
					$filas[] = array( 'nombre' => $t['apellidos'] . ', ' . $t['nombres'], 'real' => null, 'simulado' => null );
					continue;
				}

				$tarifas = array();
				foreach ( array_keys( Sueldo::TARIFAS ) as $k ) {
					$tarifas[ $k ] = $base->tarifa( $k ) + (int) ( $e['dinero'][ $k ] ?? 0 );
				}

				$sim     = $conMes( $id, $base->honorarioCentimos + (int) ( $e['dinero']['honorario'] ?? 0 ), $tarifas, $base );
				$rea     = SimulacionPago::deReal( $filaR );
				$tReal  += $rea['montos']['total'];
				$tSim   += $sim['montos']['total'];
				$filas[] = array( 'nombre' => $t['apellidos'] . ', ' . $t['nombres'], 'real' => $rea, 'simulado' => $sim );
			}

			return array( 'filas' => $filas, 'totalReal' => $tReal, 'totalSimulado' => $tSim, 'hasta' => $hasta );
		}

		// "¿Y si…?": una persona.
		$ids = array_map( static fn( array $t ): int => (int) $t['id'], $personas );
		$id  = in_array( $e['t'], $ids, true ) ? $e['t'] : ( $ids[0] ?? 0 );

		if ( ! $id ) {
			return array( 'persona' => null, 'real' => null, 'simulado' => SimulacionPago::libre( 0, array(), array() ), 'base' => null, 'hasta' => $hasta );
		}

		$filaR = $real( $id );
		$base  = $filaR->sueldoFinal ?? Sueldo::vigenteEn( $sueldos[ $id ] ?? array(), $hasta ) ?? Sueldos::vigente( $id );

		$tarifas = array();
		foreach ( array_keys( Sueldo::TARIFAS ) as $k ) {
			$tarifas[ $k ] = $e['dinero'][ $k ] ?? ( $base ? $base->tarifa( $k ) : 0 );
		}

		$honorario = $e['dinero']['honorario'] ?? ( $base ? $base->honorarioCentimos : 0 );

		return array(
			'persona'  => array_values( array_filter( $personas, static fn( $t ) => (int) $t['id'] === $id ) )[0],
			'base'     => $base,
			'cambio'   => $filaR->cambioDeSueldo,
			'real'     => $base ? SimulacionPago::deReal( $filaR ) : null,
			'simulado' => $conMes( $id, (int) $honorario, $tarifas, $base ),
			'hasta'    => $hasta,
		);
	}

	/** @param array<string, mixed> $e @param array<string, mixed> $r */
	private static function camposPersona( array $e, array $r ): void {
		$personas = Informes::personas( $e['mes'], null );
		$base     = $r['base'];
		$valor    = static fn( string $k, ?int $real ): string => '' !== $e['crudo'][ $k ] ? $e['crudo'][ $k ] : ( null === $real ? '' : number_format( $real / 100, 2, '.', '' ) );
		?>
		<h2>Persona y mes</h2>
		<p class="pk-fila">
			<label class="screen-reader-text" for="pk-sim-t">Persona</label>
			<select id="pk-sim-t" name="t" class="pk-sim-recarga">
				<?php foreach ( $personas as $t ) : ?>
					<option value="<?php echo (int) $t['id']; ?>" <?php selected( $r['persona'] ? (int) $r['persona']['id'] : 0, (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php self::selectorMes( $e ); ?>
		</p>

		<h2 class="pk-mt-s">Honorario y tarifas</h2>
		<p class="description">Vienen con sus valores reales<?php echo $base ? '' : ' (no tiene honorario registrado: escribe uno)'; ?>. Cambia los que quieras probar. El valor hora (sábado sin semana completa y madrugada) es el honorario ÷ <?php echo (int) Sueldo::HORAS_MES; ?>.<?php echo $base && $base->porHoras() ? ' Cobra por horas: se simula igual.' : ''; ?></p>
		<div class="pk-sim-campos">
			<?php self::campoDinero( 'honorario', 'Honorario mensual (S/)', $valor( 'honorario', $base ? $base->honorarioCentimos : null ) ); ?>
			<?php foreach ( Sueldo::TARIFAS as $k => $nombre ) : ?>
				<?php self::campoDinero( $k, $nombre . ' (S/ por hora)', $valor( $k, $base ? $base->tarifa( $k ) : null ) ); ?>
			<?php endforeach; ?>
		</div>

		<h2 class="pk-mt-s">Horas a sumar o restar</h2>
		<p class="description">Sobre sus horas reales del mes. Usa un signo menos para restar (por ejemplo, -2).</p>
		<?php self::camposHoras( $e, $base && $base->porHoras() ); ?>
		<?php
	}

	/** @param array<string, mixed> $e */
	private static function camposEquipo( array $e ): void {
		?>
		<h2>Mes</h2>
		<p class="pk-fila"><?php self::selectorMes( $e ); ?></p>

		<h2 class="pk-mt-s">Subir (o bajar) a todos</h2>
		<p class="description">Se suma al honorario y a las tarifas de cada persona. Usa un signo menos para bajar.</p>
		<div class="pk-sim-campos">
			<?php self::campoDinero( 'honorario', 'Honorario mensual (+ S/)', $e['crudo']['honorario'] ); ?>
			<?php foreach ( Sueldo::TARIFAS as $k => $nombre ) : ?>
				<?php self::campoDinero( $k, $nombre . ' (+ S/ por hora)', $e['crudo'][ $k ] ); ?>
			<?php endforeach; ?>
		</div>

		<h2 class="pk-mt-s">Horas a sumar a cada persona</h2>
		<p class="description">Por ejemplo, 4 horas extra más para cada uno en campaña.</p>
		<?php self::camposHoras( $e ); ?>
		<?php
	}

	/** @param array<string, mixed> $e */
	private static function camposLibre( array $e ): void {
		?>
		<h2>Honorario y tarifas</h2>
		<div class="pk-sim-campos">
			<?php self::campoDinero( 'honorario', 'Honorario mensual (S/)', $e['crudo']['honorario'] ); ?>
			<?php foreach ( Sueldo::TARIFAS as $k => $nombre ) : ?>
				<?php self::campoDinero( $k, $nombre . ' (S/ por hora)', $e['crudo'][ $k ] ); ?>
			<?php endforeach; ?>
		</div>

		<h2 class="pk-mt-s">Horas del mes</h2>
		<p class="description">Horas adicionales de cada tipo (las normales ya están en el honorario). El sábado va con la tarifa extra si completó la semana; si no, a valor hora (honorario ÷ <?php echo (int) Sueldo::HORAS_MES; ?>). La madrugada, a valor hora × <?php echo esc_html( PrePlanilla::numero( \Pekokis\Asistencia\WordPress\Ajustes::reglasPago()->multiplicadorMadrugada ) ); ?>.</p>
		<?php self::camposHoras( $e ); ?>
		<input type="hidden" name="mes" value="<?php echo esc_attr( $e['mes'] ); ?>">
		<?php
	}

	/** @param array<string, mixed> $e @param array<string, mixed> $r */
	private static function resultado( array $e, array $r ): void {
		$excel = Menu::urlAccion( 'pka_simulador_excel', array_filter( array( 'pestana' => $e['pestana'], 'mes' => $e['mes'], 't' => $e['t'] ? $e['t'] : '' ) + $e['crudo'], static fn( $v ) => '' !== $v ) );
		?>
		<div class="pk-caja pk-sim-resultado">
			<?php if ( 'equipo' === $e['pestana'] ) : ?>
				<?php $dif = $r['totalSimulado'] - $r['totalReal']; ?>
				<h2>Total del mes · <?php echo esc_html( \Pekokis\Asistencia\WordPress\Reportes::mesLargo( $e['mes'] ) ); ?></h2>
				<div class="pk-sim-totales">
					<div><span>Real</span><b><?php echo esc_html( Sueldo::soles( $r['totalReal'] ) ); ?></b></div>
					<div class="sim"><span>Simulado</span><b><?php echo esc_html( Sueldo::soles( $r['totalSimulado'] ) ); ?></b></div>
					<div class="<?php echo esc_attr( self::claseDif( $dif ) ); ?>"><span>Diferencia</span><b><?php echo esc_html( self::conSigno( $dif ) ); ?></b></div>
				</div>
				<div class="pk-desplaza">
					<table class="widefat striped pk-mt-s">
						<thead><tr><th>Persona</th><th>Real</th><th>Simulado</th><th>Diferencia</th></tr></thead>
						<tbody>
						<?php foreach ( $r['filas'] as $f ) : ?>
							<tr>
								<td><?php echo esc_html( $f['nombre'] ); ?></td>
								<?php if ( null === $f['real'] ) : ?>
									<td colspan="3"><span class="pk-chip error">Falta su honorario</span></td>
								<?php else : ?>
									<?php $d = $f['simulado']['montos']['total'] - $f['real']['montos']['total']; ?>
									<td><?php echo esc_html( Sueldo::soles( $f['real']['montos']['total'] ) ); ?></td>
									<td><strong><?php echo esc_html( Sueldo::soles( $f['simulado']['montos']['total'] ) ); ?></strong></td>
									<td class="<?php echo esc_attr( self::claseDif( $d ) ); ?>"><?php echo esc_html( self::conSigno( $d ) ); ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">Horas reales hasta el <?php echo esc_html( (string) $r['hasta'] ); ?>. Quien no tiene honorario no entra en el total.</p>
			<?php else : ?>
				<?php $conReal = null !== $r['real']; ?>
				<h2><?php echo 'libre' === $e['pestana'] ? 'Resultado' : esc_html( ( $r['persona'] ? $r['persona']['nombres'] . ' ' . $r['persona']['apellidos'] : 'Sin personas' ) . ' · ' . \Pekokis\Asistencia\WordPress\Reportes::mesLargo( $e['mes'] ) ); ?></h2>
				<div class="pk-sim-totales">
					<?php if ( $conReal ) : ?>
						<?php $dif = $r['simulado']['montos']['total'] - $r['real']['montos']['total']; ?>
						<div><span>Real</span><b><?php echo esc_html( Sueldo::soles( $r['real']['montos']['total'] ) ); ?></b></div>
						<div class="sim"><span>Simulado</span><b><?php echo esc_html( Sueldo::soles( $r['simulado']['montos']['total'] ) ); ?></b></div>
						<div class="<?php echo esc_attr( self::claseDif( $dif ) ); ?>"><span>Diferencia</span><b><?php echo esc_html( self::conSigno( $dif ) ); ?></b></div>
					<?php else : ?>
						<div class="sim"><span><?php echo 'libre' === $e['pestana'] ? 'Total del mes' : 'Simulado'; ?></span><b><?php echo esc_html( Sueldo::soles( $r['simulado']['montos']['total'] ) ); ?></b></div>
					<?php endif; ?>
				</div>
				<table class="widefat pk-mt-s pk-sim-tabla">
					<thead><tr><th>Concepto</th><th>Horas</th><?php echo $conReal ? '<th>Real</th>' : ''; ?><th><?php echo $conReal ? 'Simulado' : 'Monto'; ?></th><?php echo $conReal ? '<th>Diferencia</th>' : ''; ?></tr></thead>
					<tbody>
					<?php foreach ( self::NOMBRES as $clave => $nombre ) : ?>
						<?php
						if ( ! self::mostrar( $clave, $r ) ) {
							continue;
						}
						$esHora = in_array( $clave, SimulacionPago::TIPOS, true );
						?>
						<tr class="<?php echo 'total' === $clave ? 'pk-sim-total' : ''; ?>">
							<td><?php echo esc_html( $nombre ); ?></td>
							<td><?php echo $esHora ? esc_html( PrePlanilla::horas( $r['simulado']['minutos'][ $clave ] ) . ' h' ) : ''; ?><?php echo $esHora && $conReal && $r['simulado']['minutos'][ $clave ] !== $r['real']['minutos'][ $clave ] ? ' <span class="description">(real ' . esc_html( PrePlanilla::horas( $r['real']['minutos'][ $clave ] ) ) . ')</span>' : ''; ?></td>
							<?php if ( $conReal ) : ?>
								<td><?php echo esc_html( ( 'descuentos' === $clave ? '− ' : '' ) . Sueldo::soles( $r['real']['montos'][ $clave ] ) ); ?></td>
							<?php endif; ?>
							<td><strong><?php echo esc_html( ( 'descuentos' === $clave ? '− ' : '' ) . Sueldo::soles( $r['simulado']['montos'][ $clave ] ) ); ?></strong></td>
							<?php if ( $conReal ) : ?>
								<?php $d = $r['simulado']['montos'][ $clave ] - $r['real']['montos'][ $clave ]; ?>
								<td class="<?php echo esc_attr( self::claseDif( $d ) ); ?>"><?php echo esc_html( self::conSigno( $d ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( 'persona' === $e['pestana'] ) : ?>
					<p class="description">Horas reales hasta el <?php echo esc_html( (string) $r['hasta'] ); ?>.<?php echo ! empty( $r['cambio'] ) ? ' Este mes le cambió la tarifa: lo real usa cada tarifa en su fecha y lo simulado, una sola.' : ''; ?><?php echo $conReal ? '' : ' No tiene honorario registrado, así que no hay monto real para comparar.'; ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="pk-mt-s"><a class="button" href="<?php echo esc_url( $excel ); ?>">Descargar simulación (Excel)</a></p>
		</div>
		<?php
	}

	/** @param array<string, mixed> $e */
	private static function selectorMes( array $e ): void {
		?>
		<label class="screen-reader-text" for="pk-sim-mes">Mes</label>
		<select id="pk-sim-mes" name="mes" class="pk-sim-recarga">
			<?php foreach ( Exportes::meses() as $mes ) : ?>
				<option value="<?php echo esc_attr( $mes ); ?>" <?php selected( $e['mes'], $mes ); ?>><?php echo esc_html( ucfirst( \Pekokis\Asistencia\WordPress\Reportes::mesLargo( $mes ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function campoDinero( string $nombre, string $etiqueta, string $valor ): void {
		?>
		<p class="pk-campo">
			<label for="pk-sim-<?php echo esc_attr( $nombre ); ?>"><?php echo esc_html( $etiqueta ); ?></label>
			<input type="text" inputmode="decimal" id="pk-sim-<?php echo esc_attr( $nombre ); ?>" name="<?php echo esc_attr( $nombre ); ?>" value="<?php echo esc_attr( $valor ); ?>" placeholder="0.00" autocomplete="off">
		</p>
		<?php
	}

	/** @param array<string, mixed> $e */
	private static function camposHoras( array $e, bool $conNormales = false ): void {
		$nombres = array(
			'extra'         => 'Horas extra',
			'sabado_extra'  => 'Sábado con semana completa',
			'sabado_normal' => 'Sábado sin completar la semana',
			'domingo'       => 'Horas de domingo',
			'feriado'       => 'Horas de feriado',
			'madrugada'     => 'Horas de madrugada',
		);
		if ( $conNormales || 'equipo' === $e['pestana'] ) {
			$nombres = array( 'horas' => 'Horas normales (quien cobra por horas)' ) + $nombres;
		}
		?>
		<div class="pk-sim-campos">
			<?php foreach ( $nombres as $k => $nombre ) : ?>
				<p class="pk-campo">
					<label for="pk-sim-h-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $nombre ); ?></label>
					<input type="text" inputmode="decimal" id="pk-sim-h-<?php echo esc_attr( $k ); ?>" name="h_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $e['crudo'][ 'h_' . $k ] ); ?>" placeholder="0" autocomplete="off">
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function ayuda( string $pestana ): string {
		return match ( $pestana ) {
			'equipo' => 'Cuánto costaría el mes si se sube el honorario o una tarifa a todos, o si cada uno trabaja más horas. Con las horas reales del mes.',
			'libre'  => 'Escribe un honorario, las tarifas y las horas, y mira el total con su desglose. No usa datos reales.',
			default  => 'Elige a una persona y un mes: se cargan sus horas reales y su honorario y tarifas. Cambia cualquier valor y compara lo real con lo simulado.',
		};
	}

	/** "12.50", "1,250.50", "-2" → céntimos (con signo); vacío o inválido → null. */
	private static function dinero( string $texto ): ?int {
		if ( '' === $texto ) {
			return null;
		}

		$menos = str_starts_with( $texto, '-' );
		$c     = Sueldo::aCentimos( ltrim( $texto, '-+ ' ) );

		return null === $c ? null : ( $menos ? -$c : $c );
	}

	/** "4", "6,5", "-2", "1:30" → minutos (con signo). */
	private static function minutos( string $texto ): int {
		$t     = str_replace( ' ', '', $texto );
		$menos = str_starts_with( $t, '-' );
		$t     = ltrim( $t, '-+' );

		if ( preg_match( '/^(\d{1,4}):([0-5]\d)$/', $t, $m ) ) {
			$min = (int) $m[1] * 60 + (int) $m[2];
		} elseif ( preg_match( '/^\d{1,4}([.,]\d{1,2})?$/', $t ) ) {
			$min = (int) round( (float) str_replace( ',', '.', $t ) * 60 );
		} else {
			return 0;
		}

		return $menos ? -$min : $min;
	}

	/** @param array<string, mixed> $r */
	private static function mostrar( string $clave, array $r ): bool {
		if ( ! in_array( $clave, self::OPCIONALES, true ) ) {
			return true;
		}

		return ( $r['simulado']['montos'][ $clave ] ?? 0 ) || ( $r['real']['montos'][ $clave ] ?? 0 ) || ( $r['simulado']['minutos'][ $clave ] ?? 0 );
	}

	private static function claseDif( int $d ): string {
		return $d > 0 ? 'pk-sim-sube' : ( $d < 0 ? 'pk-sim-baja' : 'pk-sim-igual' );
	}

	private static function conSigno( int $c ): string {
		return ( $c > 0 ? '+ ' : ( $c < 0 ? '− ' : '' ) ) . Sueldo::soles( abs( $c ) );
	}

	/** Queda en la auditoría quién usó el simulador (una vez al día por persona). */
	private static function registrarUso(): void {
		$clave = 'pka_sim_' . get_current_user_id() . '_' . wp_date( 'Ymd' );

		if ( false === get_transient( $clave ) ) {
			set_transient( $clave, 1, DAY_IN_SECONDS );
			Auditoria::registrar( 'simulador.usar', 'registro', null, null, array( 'fecha' => wp_date( 'Y-m-d' ) ) );
		}
	}
}
