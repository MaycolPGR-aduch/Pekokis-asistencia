<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Informe por trabajador (fase 3): calendario del mes con colores
 * por estado, el detalle de cada día y los totales. Se descarga en Excel o se
 * imprime (y desde ahí se guarda como PDF).
 */
final class PantallaInforme {
	public const SLUG = 'pka-informe';

	public static function registrar(): void {
		add_action( 'admin_post_pka_informe_excel', array( self::class, 'excel' ) );
		add_action( 'admin_post_pka_informe_imprimir', array( self::class, 'imprimir' ) );
	}

	public static function url( int $trabajadorId = 0, string $mes = '' ): string {
		return add_query_arg( array_filter( array( 'page' => self::SLUG, 'trabajador' => $trabajadorId, 'mes' => $mes ) ), admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$mes      = PantallaResumen::mesPedido();
		$alcance  = PantallaHoy::alcance();
		$personas = Informes::personas( $mes, $alcance );
		$id       = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t        = null;

		foreach ( $personas as $p ) {
			if ( (int) $p['id'] === $id ) {
				$t = $p;
			}
		}

		usort( $personas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Informe por trabajador</h1>
			<form method="get" style="margin:8px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="pka-i-trabajador">Trabajador</label>
				<select id="pka-i-trabajador" name="trabajador">
					<option value="">— Elige —</option>
					<?php foreach ( $personas as $p ) : ?>
						<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $id, (int) $p['id'] ); ?>><?php echo esc_html( $p['apellidos'] . ', ' . $p['nombres'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="pka-i-mes">Mes</label>
				<select id="pka-i-mes" name="mes">
					<?php foreach ( Exportes::meses() as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $mes ); ?>><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary">Ver informe</button>
			</form>

			<?php if ( ! $t ) : ?>
				<p><?php echo $id ? 'Esa persona no trabajó ese mes o no es de tu equipo.' : 'Elige a un trabajador y el mes.'; ?></p>
			<?php else : ?>
				<p>
					<a class="button" href="<?php echo esc_url( Menu::urlAccion( 'pka_informe_excel', array( 'trabajador' => (int) $t['id'], 'mes' => $mes ) ) ); ?>">Descargar Excel</a>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Menu::urlAccion( 'pka_informe_imprimir', array( 'trabajador' => (int) $t['id'], 'mes' => $mes ) ) ); ?>">Imprimir o guardar PDF</a>
				</p>
				<?php self::cuerpo( $t, $mes, false ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Totales, calendario y detalle. Lo usan la pantalla y la versión para imprimir. */
	public static function cuerpo( array $t, string $mes, bool $imprimir ): void {
		$dias    = Informes::diasDe( $t, $mes );
		$marcas  = Informes::marcasDelMes( (int) $t['id'], $mes );
		$fila    = Jornadas::prePlanilla( array( $t ), $mes )[ (int) $t['id'] ] ?? new PrePlanilla();
		$h       = static fn( int $min ): string => PrePlanilla::horas( $min );
		$sueldos = current_user_can( Capacidades::VER_SUELDOS );
		$zona    = Ajustes::zona();
		$hoy     = wp_date( 'Y-m-d', null, $zona );

		PantallaResumen::estilos();
		self::estilos();
		?>
		<h2 style="margin-bottom:2px"><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?> · <?php echo esc_html( PantallaResumen::nombreMes( $mes ) ); ?></h2>
		<p class="description">DNI <?php echo esc_html( $t['dni'] ); ?><?php echo '' !== (string) $t['area'] ? ' · ' . esc_html( $t['area'] ) : ''; ?><?php echo '' !== (string) $t['cargo'] ? ' · ' . esc_html( $t['cargo'] ) : ''; ?><?php echo Trabajadores::CESADO === $t['estado'] ? ' · Cesó el ' . esc_html( (string) $t['fecha_cese'] ) : ''; ?></p>

		<div class="pka-tarjetas">
			<?php
			self::tarjeta( 'Días trabajados', $fila->dias['trabajados'] . ' de ' . $fila->dias['laborables'] );
			self::tarjeta( 'Horas trabajadas', $h( $fila->minutos['trabajados'] ) );
			self::tarjeta( 'Faltas', (string) $fila->dias['faltas'], PantallaResumen::plural( $fila->dias['justificados'], 'justificado', 'justificados' ) );
			self::tarjeta( 'Tardanzas', (string) $fila->dias['con_tardanza'], $fila->minutos['tardanza'] . ' min en total' );
			self::tarjeta( 'Puntualidad', null === $fila->puntualidad() ? '—' : $fila->puntualidad() . ' %' );
			self::tarjeta( 'Horas extra', $h( $fila->minutos['extra'] ), 'de lunes a viernes' );
			if ( $fila->minutos['sabado'] + $fila->minutos['domingo'] + $fila->minutos['feriado'] ) {
				self::tarjeta( 'Sábado, domingo y feriado', $h( $fila->minutos['sabado'] + $fila->minutos['domingo'] + $fila->minutos['feriado'] ), 'sáb ' . $h( $fila->minutos['sabado'] ) . ' · dom ' . $h( $fila->minutos['domingo'] ) . ' · fer ' . $h( $fila->minutos['feriado'] ) );
			}
			if ( $sueldos ) {
				self::tarjeta( 'Pago del mes', $fila->sueldoFinal ? Sueldo::soles( $fila->montos['total'] ) : '—', $fila->sueldoFinal ? 'honorario ' . Sueldo::soles( $fila->montos['honorario'] ) . ' + ' . Sueldo::soles( $fila->costoExtra() ) : 'falta su honorario' );
			}
			?>
		</div>

		<?php
		$primero = new DateTimeImmutable( $mes . '-01' );
		$vacios  = (int) $primero->format( 'N' ) - 1;
		$ultimo  = (int) $primero->format( 't' );
		?>
		<table class="pka-cal" aria-label="Calendario del mes">
			<thead><tr><th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th></tr></thead>
			<tbody><tr>
			<?php
			for ( $i = 0; $i < $vacios; $i++ ) {
				echo '<td class="pka-cal-vacio"></td>';
			}

			for ( $d = 1; $d <= $ultimo; $d++ ) {
				$dia   = $primero->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
				$j     = $dias[ $dia ] ?? null;
				$clave = Informes::estadoVisual( $j );

				[ $etiqueta, $color, $fondo ] = Informes::COLORES[ $clave ];

				if ( ! $j ) {
					$etiqueta = $dia >= $hoy ? '' : '—';
				} elseif ( in_array( $clave, array( 'justificado', 'descanso' ), true ) && '' !== $j->detalle ) {
					$etiqueta = preg_replace( '/^Feriado: /', '', $j->detalle );
				}

				$horas = $j && $j->minutosTrabajados ? $h( $j->minutosTrabajados ) . ' h' : '';
				$extra = $j && $j->minutosTardanza ? '+' . $j->minutosTardanza . ' min' : '';

				echo '<td style="background:' . esc_attr( $fondo ) . '"><div class="pka-cal-n">' . (int) $d . '</div>'
					. '<div class="pka-cal-e" style="color:' . esc_attr( $color ) . '">' . esc_html( $etiqueta ) . '</div>'
					. '<div class="pka-cal-h">' . esc_html( trim( $horas . ' ' . $extra ) ) . '</div></td>';

				if ( 0 === ( $vacios + $d ) % 7 && $d < $ultimo ) {
					echo '</tr><tr>';
				}
			}

			$resto = ( 7 - ( $vacios + $ultimo ) % 7 ) % 7;
			for ( $i = 0; $i < $resto; $i++ ) {
				echo '<td class="pka-cal-vacio"></td>';
			}
			?>
			</tr></tbody>
		</table>
		<p class="pka-leyenda">
			<?php foreach ( Informes::COLORES as $clave => [ $etiqueta, $color, $fondo ] ) : ?>
				<?php if ( 'pendiente' !== $clave ) : ?>
					<span style="background:<?php echo esc_attr( $fondo ); ?>;color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $etiqueta ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>

		<h3>Detalle de cada día</h3>
		<table class="widefat striped pka-detalle">
			<thead><tr><th>Día</th><th>Estado</th><th>Marcas</th><th>Trabajado</th><th>Tardanza</th><th>Extra</th><th>Sáb./dom./fer.</th><th>Nota</th></tr></thead>
			<tbody>
			<?php if ( ! $dias ) : ?>
				<tr><td colspan="8">Todavía no hay días terminados en este mes.</td></tr>
			<?php endif; ?>
			<?php foreach ( $dias as $dia => $j ) : ?>
				<?php [ $etiqueta, $color ] = Informes::COLORES[ Informes::estadoVisual( $j ) ]; ?>
				<tr>
					<td>
						<?php if ( $imprimir ) : ?>
							<?php echo esc_html( wp_date( 'D j', strtotime( $dia . ' 12:00:00' ) ) ); ?>
						<?php else : ?>
							<a href="<?php echo esc_url( PantallaDia::url( (int) $t['id'], $dia ) ); ?>"><?php echo esc_html( wp_date( 'D j', strtotime( $dia . ' 12:00:00' ) ) ); ?></a>
						<?php endif; ?>
					</td>
					<td style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( $etiqueta ); ?></td>
					<td>
						<?php foreach ( $marcas[ $dia ] ?? array() as $m ) : ?>
							<span style="<?php echo null !== $m['anulada'] ? 'text-decoration:line-through;color:#8c8f94' : ''; ?>"><?php echo esc_html( Informes::textoMarca( $m ) ); ?></span><?php echo 'trabajador' !== $m['origen'] ? ' <em>(' . esc_html( PantallaDia::ORIGENES[ $m['origen'] ] ?? $m['origen'] ) . ')</em>' : ''; ?><br>
						<?php endforeach; ?>
					</td>
					<td><?php echo $j->minutosTrabajados ? esc_html( $h( $j->minutosTrabajados ) ) : ''; ?></td>
					<td><?php echo $j->minutosTardanza ? (int) $j->minutosTardanza . ' min' : ''; ?></td>
					<td><?php echo $j->minutosExtra ? esc_html( $h( $j->minutosExtra ) ) : ''; ?></td>
					<td><?php echo $j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado ? esc_html( $h( $j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado ) ) : ''; ?></td>
					<td><?php echo esc_html( $j->detalle ); ?><?php echo $j->minutosSalidaAnticipada ? esc_html( ( '' !== $j->detalle ? ' · ' : '' ) . 'Salió ' . $j->minutosSalidaAnticipada . ' min antes' ) : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function excel(): void {
		$t   = self::exigirTrabajador( 'pka_informe_excel' );
		$mes = self::mesDeLaPeticion();
		$xls = Informes::excelTrabajador( $t, $mes, current_user_can( Capacidades::VER_SUELDOS ), wp_get_current_user()->display_name );

		Auditoria::registrar( 'informe.descargar', 'trabajador', (int) $t['id'], null, array( 'mes' => $mes, 'formato' => 'Excel' ) );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $xls['nombre'] . '"' );
		header( 'Content-Length: ' . strlen( $xls['contenido'] ) );
		echo $xls['contenido']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- archivo binario.
		exit;
	}

	/** Página suelta, sin el menú de wp-admin, lista para imprimir o guardar como PDF. */
	public static function imprimir(): void {
		$t   = self::exigirTrabajador( 'pka_informe_imprimir' );
		$mes = self::mesDeLaPeticion();
		$aj  = Ajustes::todos();

		Auditoria::registrar( 'informe.descargar', 'trabajador', (int) $t['id'], null, array( 'mes' => $mes, 'formato' => 'Impresión / PDF' ) );

		nocache_headers();
		?>
		<!doctype html>
		<html lang="es">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( 'Informe ' . $mes . ' — ' . Trabajadores::nombreCompleto( $t ) ); ?></title>
			<style>
				body{font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#fff;margin:24px}
				h1{font-size:18px;margin:0}h2{font-size:16px}h3{font-size:14px;margin-top:20px}
				.description{color:#50575e;margin:2px 0 10px}
				table.widefat{border-collapse:collapse;width:100%}
				table.widefat th,table.widefat td{border:1px solid #dcdcde;padding:4px 6px;text-align:left;vertical-align:top}
				.pka-barra{margin-bottom:16px}
				@media print{.pka-barra{display:none}body{margin:0}.pka-tarjeta,.pka-cal td,.pka-leyenda span{-webkit-print-color-adjust:exact;print-color-adjust:exact}tr{break-inside:avoid}}
			</style>
		</head>
		<body>
			<div class="pka-barra"><button onclick="window.print()">Imprimir o guardar como PDF</button></div>
			<h1><?php echo esc_html( (string) $aj['razon_social'] ); ?> · RUC <?php echo esc_html( (string) $aj['ruc'] ); ?></h1>
			<p class="description">Informe de asistencia generado el <?php echo esc_html( wp_date( 'Y-m-d H:i', null, Ajustes::zona() ) ); ?> por <?php echo esc_html( wp_get_current_user()->display_name ); ?>.</p>
			<?php self::cuerpo( $t, $mes, true ); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/** @return array<string, mixed> */
	private static function exigirTrabajador( string $accion ): array {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );

		$id      = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0;
		$t       = Trabajadores::buscar( $id );
		$alcance = PantallaHoy::alcance();

		if ( ! $t || ( null !== $alcance && ! in_array( $id, $alcance, true ) ) ) {
			wp_die( 'Ese trabajador no es de tu equipo.', 403 );
		}

		return $t;
	}

	private static function mesDeLaPeticion(): string {
		$mes = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verificado en exigirTrabajador().

		if ( ! in_array( $mes, Exportes::meses(), true ) ) {
			wp_die( 'Mes no válido.', 400 );
		}

		return $mes;
	}

	private static function tarjeta( string $titulo, string $valor, string $pie = '' ): void {
		?>
		<div class="pka-tarjeta">
			<div class="pka-tarjeta-titulo"><?php echo esc_html( $titulo ); ?></div>
			<div class="pka-tarjeta-valor"><?php echo esc_html( $valor ); ?></div>
			<?php if ( '' !== $pie ) : ?>
				<div class="pka-tarjeta-pie"><?php echo esc_html( $pie ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function estilos(): void {
		?>
		<style>
			.pka-cal{border-collapse:separate;border-spacing:4px;width:100%;max-width:900px;table-layout:fixed}
			.pka-cal th{color:#50575e;font-weight:600;font-size:12px;text-align:left;padding:0 6px}
			.pka-cal td{border:1px solid #dcdcde;border-radius:6px;height:64px;vertical-align:top;padding:4px 6px}
			.pka-cal td.pka-cal-vacio{border:0;background:transparent}
			.pka-cal-n{font-weight:600;font-size:12px;color:#1d2327}
			.pka-cal-e{font-size:12px;font-weight:600;line-height:1.2;overflow-wrap:anywhere}
			.pka-cal-h{font-size:11px;color:#50575e}
			.pka-leyenda{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px}
			.pka-leyenda span{border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600}
			.pka-detalle td{font-size:12px}
		</style>
		<?php
	}
}
