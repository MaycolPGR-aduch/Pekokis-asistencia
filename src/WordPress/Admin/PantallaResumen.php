<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Resumen del mes (dashboard general, fase 3): horas, horas extra,
 * tardanzas, faltas y feriados trabajados del equipo, por trabajador y en total,
 * comparados con los meses anteriores.
 */
final class PantallaResumen {
	public const SLUG = 'pka-resumen';

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/** Mes pedido por GET, si es uno de los permitidos; si no, el actual. */
	public static function mesPedido(): string {
		$meses = Exportes::meses();
		$mes   = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $mes, $meses, true ) ? $mes : $meses[0];
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$mes       = self::mesPedido();
		$alcance   = PantallaHoy::alcance();
		$datos     = Informes::mes( $mes, $alcance );
		$total     = $datos['total'];
		$sueldos   = current_user_can( Capacidades::VER_SUELDOS );
		$historico = Informes::comparacion( $mes, $alcance );
		$h         = static fn( int $min ): string => PrePlanilla::horas( $min );
		$hasta     = min( wp_date( 'Y-m-t', strtotime( $mes . '-01' ) ), Jornadas::ayer() );
		$filas     = $datos['personas'];

		usort( $filas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Resumen del mes</h1>
			<form method="get" style="margin:8px 0 4px">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="pka-r-mes">Mes</label>
				<select id="pka-r-mes" name="mes" onchange="this.form.submit()">
					<?php foreach ( Exportes::meses() as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $mes ); ?>><?php echo esc_html( self::nombreMes( $m ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button">Ver</button></noscript>
			</form>
			<p class="description">
				<?php echo is_array( $alcance ) ? 'Solo tu equipo.' : 'Todo el taller.'; ?>
				<?php echo $hasta >= $mes . '-01' ? esc_html( 'Días calculados hasta el ' . $hasta . ' (el día de hoy no entra).' ) : 'Este mes todavía no tiene días terminados.'; ?>

			</p>

			<?php self::estilos(); ?>
			<div class="pka-tarjetas">
				<?php
				self::tarjeta( 'Horas trabajadas', $h( $total->minutos['trabajados'] ), self::plural( $total->dias['trabajados'], 'día trabajado', 'días trabajados' ) );
				self::tarjeta( 'Horas extra', $h( $total->minutos['extra'] ), $sueldos ? Sueldo::soles( $total->montos['extra'] ) . ' · de lunes a viernes' : 'de lunes a viernes' );
				self::tarjeta( 'Sábado, domingo y feriado', $h( ( $total->minutos['sabado'] + $total->minutos['domingo'] + $total->minutos['feriado'] ) ), $sueldos ? Sueldo::soles( $total->montos['sabado'] + $total->montos['domingo'] + $total->montos['feriado'] ) : self::plural( $total->dias['sabados'] + $total->dias['domingos'] + $total->dias['feriados'], 'día trabajado', 'días trabajados' ) );
				self::tarjeta( 'Tardanzas', (string) $total->dias['con_tardanza'], $h( $total->minutos['tardanza'] ) . ' h en total', $total->dias['con_tardanza'] ? '#8a4b00' : '' );
				self::tarjeta( 'Faltas', (string) $total->dias['faltas'], self::plural( $total->dias['justificados'], 'día justificado', 'días justificados' ), $total->dias['faltas'] ? '#b32d2e' : '' );
				self::tarjeta( 'Puntualidad', null === $total->puntualidad() ? '—' : $total->puntualidad() . ' %', 'días que llegaron a tiempo' );
				if ( $sueldos ) {
					self::tarjeta( 'Total a pagar', Sueldo::soles( $total->montos['total'] ), 'honorarios ' . Sueldo::soles( $total->montos['honorario'] ) . ' + horas ' . Sueldo::soles( $total->costoExtra() ) );
				}
				self::tarjeta( 'Días por revisar', (string) ( $total->dias['por_revisar'] + $total->dias['incompletos'] ), self::plural( $total->dias['incompletos'], 'incompleto', 'incompletos' ) . ' (sin salida)' );
				?>
			</div>

			<?php self::graficos( $mes, $historico, $datos ); ?>

			<h2>Comparación con los meses anteriores</h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr>
					<th>Mes</th><th>Horas trabajadas</th><th>Horas extra</th><th>Sáb./dom./fer.</th><th>Tardanzas</th><th>Faltas</th><th>Puntualidad</th>
					<?php if ( $sueldos ) : ?><th>Total a pagar (S/)</th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php
				$meses = array_keys( $historico );
				foreach ( $meses as $i => $m ) :
					$t      = $historico[ $m ];
					$previo = $historico[ $meses[ $i + 1 ] ?? '' ] ?? null;
					?>
					<tr<?php echo $m === $mes ? ' style="font-weight:600"' : ''; ?>>
						<td><?php echo esc_html( self::nombreMes( $m ) ); ?></td>
						<td><?php echo esc_html( $h( $t->minutos['trabajados'] ) ); ?><?php self::tendencia( $t->minutos['trabajados'], $previo?->minutos['trabajados'], false ); ?></td>
						<td><?php echo esc_html( $h( $t->minutos['extra'] ) ); ?><?php self::tendencia( $t->minutos['extra'], $previo?->minutos['extra'], true ); ?></td>
						<td><?php echo esc_html( $h( ( $t->minutos['sabado'] + $t->minutos['domingo'] + $t->minutos['feriado'] ) ) ); ?></td>
						<td><?php echo (int) $t->dias['con_tardanza']; ?><?php self::tendencia( $t->dias['con_tardanza'], $previo?->dias['con_tardanza'], true ); ?></td>
						<td><?php echo (int) $t->dias['faltas']; ?><?php self::tendencia( $t->dias['faltas'], $previo?->dias['faltas'], true ); ?></td>
						<td><?php echo null === $t->puntualidad() ? '—' : (int) $t->puntualidad() . ' %'; ?></td>
						<?php if ( $sueldos ) : ?><td><?php echo esc_html( number_format( $t->montos['total'] / 100, 2, '.', ',' ) ); ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">▲ subió y ▼ bajó respecto al mes anterior. En rojo, lo que empeoró.</p>

			<h2>Por trabajador</h2>
			<div style="overflow-x:auto"><table class="widefat striped">
				<thead><tr>
					<th>Trabajador</th><th>Días trabajados</th><th>Horas</th><th>Tardanzas</th><th>Faltas</th><th>Justificados</th><th>Horas extra</th><th>Sáb./dom./fer.</th><th>Puntualidad</th>
					<?php if ( $sueldos ) : ?><th>Total (S/)</th><th>Estado</th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?>
					<tr><td colspan="11"><?php echo is_array( $alcance ) ? 'No tienes trabajadores a tu cargo.' : 'No hay trabajadores en este mes.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $filas as $p ) : ?>
					<?php $f = $datos['filas'][ (int) $p['id'] ] ?? new PrePlanilla(); ?>
					<tr>
						<td><a href="<?php echo esc_url( PantallaInforme::url( (int) $p['id'], $mes ) ); ?>"><strong><?php echo esc_html( $p['apellidos'] . ', ' . $p['nombres'] ); ?></strong></a><br><span class="description"><?php echo esc_html( $p['area'] ); ?></span></td>
						<td><?php echo (int) $f->dias['trabajados']; ?> de <?php echo (int) $f->dias['laborables']; ?></td>
						<td><?php echo esc_html( $h( $f->minutos['trabajados'] ) ); ?></td>
						<td><?php echo $f->dias['con_tardanza'] ? (int) $f->dias['con_tardanza'] . ' (' . (int) $f->minutos['tardanza'] . ' min)' : '0'; ?></td>
						<td<?php echo $f->dias['faltas'] ? ' style="color:#b32d2e;font-weight:600"' : ''; ?>><?php echo (int) $f->dias['faltas']; ?></td>
						<td><?php echo (int) $f->dias['justificados']; ?></td>
						<td><?php echo esc_html( $h( $f->minutos['extra'] ) ); ?></td>
						<td><?php echo esc_html( $h( ( $f->minutos['sabado'] + $f->minutos['domingo'] + $f->minutos['feriado'] ) ) ); ?></td>
						<td><?php echo null === $f->puntualidad() ? '—' : (int) $f->puntualidad() . ' %'; ?></td>
						<?php if ( $sueldos ) : ?>
							<td><?php echo $f->sueldoFinal ? esc_html( number_format( $f->montos['total'] / 100, 2, '.', ',' ) ) : '—'; ?></td>
							<td<?php echo PrePlanilla::LISTO === $f->estado() ? ' style="color:#00733a"' : ' style="color:#b32d2e"'; ?>><?php echo esc_html( PrePlanilla::ESTADOS[ $f->estado() ] ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>
		<?php
	}

	/**
	 * Tres gráficos por mes (horas trabajadas, horas extra y faltas, cada uno con
	 * su propio eje) y uno por trabajador con las horas del mes elegido.
	 *
	 * @param array<string, PrePlanilla> $historico
	 * @param array{filas: array<int, PrePlanilla>, personas: array<int, array<string, mixed>>} $datos
	 */
	private static function graficos( string $mes, array $historico, array $datos ): void {
		$corto  = static fn( string $m ): string => wp_date( 'M', strtotime( $m . '-15 12:00:00' ) );
		$horas  = static fn( int $min ): float => round( $min / 60, 1 );
		$meses  = array_reverse( $historico, true );
		$series = array(
			'Horas trabajadas por mes' => static fn( PrePlanilla $t ): int => $t->minutos['trabajados'],
			'Horas extra por mes'             => static fn( PrePlanilla $t ): int => $t->minutos['extra'] + $t->minutos['sabado'] + $t->minutos['domingo'] + $t->minutos['feriado'],
		);

		echo '<h2>Cómo va el mes</h2><div class="pka-graficos">';

		foreach ( $series as $titulo => $valor ) {
			$puntos = array();

			foreach ( $meses as $m => $t ) {
				$min      = $valor( $t );
				$puntos[] = array( 'etiqueta' => $corto( $m ), 'valor' => $horas( $min ), 'texto' => PrePlanilla::horas( $min ) . ' h', 'detalle' => self::nombreMes( $m ) . ': ' . PrePlanilla::horas( $min ) . ' h' );
			}

			echo Graficos::columnas( $titulo, $puntos, $corto( $mes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG armado y escapado en Graficos.
		}

		$faltas = array();

		foreach ( $meses as $m => $t ) {
			$faltas[] = array( 'etiqueta' => $corto( $m ), 'valor' => (float) $t->dias['faltas'], 'texto' => (string) $t->dias['faltas'], 'detalle' => self::nombreMes( $m ) . ': ' . self::plural( $t->dias['faltas'], 'falta', 'faltas' ) . ', ' . self::plural( $t->dias['con_tardanza'], 'tardanza', 'tardanzas' ) );
		}

		echo Graficos::columnas( 'Faltas por mes', $faltas, $corto( $mes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$porPersona = array();

		foreach ( $datos['personas'] as $p ) {
			$f            = $datos['filas'][ (int) $p['id'] ] ?? new PrePlanilla();
			$porPersona[] = array(
				'etiqueta' => $p['apellidos'] . ', ' . $p['nombres'],
				'valor'    => $horas( $f->minutos['trabajados'] ),
				'texto'    => PrePlanilla::horas( $f->minutos['trabajados'] ) . ' h',
				'detalle'  => $p['nombres'] . ' ' . $p['apellidos'] . ': ' . PrePlanilla::horas( $f->minutos['trabajados'] ) . ' h en ' . self::plural( $f->dias['trabajados'], 'día', 'días' ) . ( $f->dias['faltas'] ? ', ' . self::plural( $f->dias['faltas'], 'falta', 'faltas' ) : '' ),
			);
		}

		if ( $porPersona ) {
			echo Graficos::barras( 'Horas trabajadas por persona · ' . self::nombreMes( $mes ), $porPersona ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	/** "1 día justificado", "3 días justificados". */
	public static function plural( int $n, string $uno, string $varios ): string {
		return $n . ' ' . ( 1 === $n ? $uno : $varios );
	}

	/** "2026-11" → "noviembre 2026". */
	public static function nombreMes( string $mes ): string {
		return wp_date( 'F Y', strtotime( $mes . '-15 12:00:00' ) );
	}

	private static function tarjeta( string $titulo, string $valor, string $pie = '', string $color = '' ): void {
		?>
		<div class="pka-tarjeta">
			<div class="pka-tarjeta-titulo"><?php echo esc_html( $titulo ); ?></div>
			<div class="pka-tarjeta-valor"<?php echo '' !== $color ? ' style="color:' . esc_attr( $color ) . '"' : ''; ?>><?php echo esc_html( $valor ); ?></div>
			<?php if ( '' !== $pie ) : ?>
				<div class="pka-tarjeta-pie"><?php echo wp_kses( $pie, array( 'a' => array( 'href' => array() ) ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Flecha respecto al mes anterior; si $malSiSube, subir se pinta en rojo. */
	private static function tendencia( int $actual, ?int $anterior, bool $malSiSube ): void {
		if ( null === $anterior || $actual === $anterior ) {
			return;
		}

		$sube  = $actual > $anterior;
		$color = $sube === $malSiSube ? '#b32d2e' : '#00733a';

		echo ' <span style="color:' . esc_attr( $color ) . '" title="Mes anterior: ' . esc_attr( (string) $anterior ) . '">' . ( $sube ? '▲' : '▼' ) . '</span>';
	}

	public static function estilos(): void {
		?>
		<style>
			.pka-tarjetas{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin:16px 0 24px;max-width:1100px}
			.pka-tarjeta{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
			.pka-tarjeta-titulo{color:#50575e;font-size:13px}
			.pka-tarjeta-valor{font-size:26px;font-weight:600;line-height:1.3;margin:4px 0;font-variant-numeric:tabular-nums}
			.pka-tarjeta-pie{color:#646970;font-size:12px}
		</style>
		<?php
	}
}
