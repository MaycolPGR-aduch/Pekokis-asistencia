<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Pagos;
use Pekokis\Asistencia\WordPress\PrePlanillas;
use Pekokis\Asistencia\WordPress\Recibos;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Pagos (calculadora, G10 de las maquetas). Sacar el pago en
 * cuatro pasos: ver el total, revisar qué falta, ver cuánto se paga a cada
 * persona y descargar la planilla. Las horas vienen de la asistencia; el
 * recibo no frena el pago.
 */
final class PantallaPagos {
	public const SLUG = 'pka-pagos';

	public static function registrar(): void {
		add_action( 'admin_post_pka_pago_marcar', array( self::class, 'marcar' ) );
		add_action( 'admin_post_pka_pago_desmarcar', array( self::class, 'desmarcar' ) );
		add_action( 'admin_post_pka_planilla_listos', array( self::class, 'planillaListos' ) );
	}

	public static function url( string $mes = '' ): string {
		return add_query_arg( array_filter( array( 'page' => self::SLUG, 'mes' => $mes ) ), admin_url( 'admin.php' ) );
	}

	/** Mes por defecto: el anterior, que es el que se paga. */
	private static function mesPedido(): string {
		$meses = Exportes::meses();
		$mes   = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $mes, $meses, true ) ? $mes : ( $meses[1] ?? $meses[0] );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$mes      = self::mesPedido();
		$area     = FiltroArea::actual();
		$datos    = Informes::mes( $mes, FiltroArea::ids( $area ) );
		$total    = $datos['total'];
		$pagos    = Pagos::delMes( $mes );
		$recibos  = Recibos::delMes( $mes );
		$personas = $datos['personas'];
		$listos   = 0;
		$cuenta   = array( 'incidencia' => array(), 'sin_tarifa' => array(), 'recibidos' => 0, 'por_revisar' => 0, 'observados' => 0, 'faltan' => 0 );

		usort( $personas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );

		foreach ( $personas as $p ) {
			$f      = $datos['filas'][ (int) $p['id'] ] ?? new PrePlanilla();
			$estado = $f->estado();
			$r      = $recibos[ (int) $p['id'] ] ?? null;

			if ( PrePlanilla::INCIDENCIA === $estado ) {
				$cuenta['incidencia'][] = $p;
			} elseif ( PrePlanilla::SIN_TARIFA === $estado ) {
				$cuenta['sin_tarifa'][] = $p;
			} elseif ( ! isset( $pagos[ (int) $p['id'] ] ) ) {
				++$listos;
			}

			match ( $r['estado'] ?? '' ) {
				ValidacionRecibo::RECIBIDO  => ++$cuenta['recibidos'],
				ValidacionRecibo::ENVIADO   => ++$cuenta['por_revisar'],
				ValidacionRecibo::OBSERVADO => ++$cuenta['observados'],
				default                     => ++$cuenta['faltan'],
			};
		}

		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Pagos de <?php echo esc_html( PantallaResumen::nombreMes( $mes ) ); ?></h1>
			<form class="pk-mt-s" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="pka-p-mes">Mes</label>
				<select id="pka-p-mes" name="mes" onchange="this.form.submit()">
					<?php foreach ( Exportes::meses() as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $mes ); ?>><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php FiltroArea::selector( $area, 'pka-p-area' ); ?>
				<noscript><button class="button">Ver</button></noscript>
			</form>
			<?php if ( '' !== $area ) : ?>
				<p><span class="pk-chip info"><?php echo esc_html( 'Solo el área ' . $area ); ?></span> <a href="<?php echo esc_url( self::url( $mes ) ); ?>">Ver todas</a></p>
			<?php endif; ?>
			<p class="description">Las horas vienen de la asistencia (días terminados hasta <?php echo esc_html( min( wp_date( 'Y-m-t', strtotime( $mes . '-15' ) ), Jornadas::ayer() ) ); ?>). Si cambia una tarifa o un registro, el cálculo se actualiza solo. El recibo por honorarios no frena el pago: puede llegar después.</p>

			<div class="pk-kpis tres pk-max">
				<div class="pk-kpi dest">
					<span>Total del mes</span>
					<b><?php echo esc_html( Sueldo::soles( $total->montos['total'] ) ); ?></b>
					<small>honorarios <?php echo esc_html( Sueldo::soles( $total->montos['honorario'] ) ); ?> + horas <?php echo esc_html( Sueldo::soles( $total->costoExtra() ) ); ?></small>
				</div>
				<div class="pk-kpi ancha">
					<span>Antes de pagar</span>
					<ul class="pk-lista-puntos">
						<li><?php echo $cuenta['incidencia'] ? '<strong class="pk-txt-error">' . esc_html( PantallaResumen::plural( count( $cuenta['incidencia'] ), 'persona con días por resolver', 'personas con días por resolver' ) ) . ':</strong> ' . self::enlaces( $cuenta['incidencia'], $mes ) : '✔ Asistencia: no hay días incompletos ni marcas por revisar.'; ?></li>
						<li><?php echo $cuenta['sin_tarifa'] ? '<strong class="pk-txt-error">' . esc_html( PantallaResumen::plural( count( $cuenta['sin_tarifa'] ), 'persona sin honorario', 'personas sin honorario' ) ) . ':</strong> ' . self::enlaces( $cuenta['sin_tarifa'], $mes, true ) : '✔ Tarifas: todos tienen honorario y tarifas.'; ?></li>
						<li>Recibos: <?php echo (int) $cuenta['recibidos']; ?> recibidos · <?php echo (int) $cuenta['por_revisar']; ?> por revisar · <?php echo (int) $cuenta['observados']; ?> observados · <?php echo (int) $cuenta['faltan']; ?> faltan. <a href="<?php echo esc_url( PantallaRecibos::url( $mes ) ); ?>">Ver recibos</a></li>
					</ul>
				</div>
			</div>

			<div class="pk-desplaza">
			<table class="pk-tabla-pagos widefat striped">
				<thead><tr><th>Persona</th><th>Honorario</th><th>Horas adicionales</th><th>Total</th><th>Recibo</th><th>Estado</th><th>Pagado</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $personas ) : ?>
					<tr><td colspan="8">No hay trabajadores en este mes.</td></tr>
				<?php endif; ?>
				<?php foreach ( $personas as $p ) : ?>
					<?php
					$id     = (int) $p['id'];
					$f      = $datos['filas'][ $id ] ?? new PrePlanilla();
					$estado = $f->estado();
					$r      = $recibos[ $id ] ?? null;
					$pago   = $pagos[ $id ] ?? null;
					?>
					<tr>
						<td><strong><?php echo esc_html( $p['apellidos'] . ', ' . $p['nombres'] ); ?></strong><br><?php echo FiltroArea::chips( (string) $p['area'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chips() escapa. ?></td>
						<td><?php echo $f->sueldoFinal ? ( $f->porHoras() ? '<span class="pk-chip info">Por horas</span>' : esc_html( Sueldo::soles( $f->montos['honorario'] ) ) ) : '—'; ?></td>
						<td><?php echo esc_html( PrePlanilla::horas( $f->minutosAdicionales() ) . ' h' ); ?><?php echo $f->costoExtra() ? '<br><span class="description">' . esc_html( Sueldo::soles( $f->costoExtra() ) ) . '</span>' : ''; ?></td>
						<td><strong><?php echo $f->sueldoFinal ? esc_html( Sueldo::soles( $f->montos['total'] ) ) : '—'; ?></strong><?php echo $f->montos['descuentos'] ? '<br><span class="description">' . esc_html( 'desc. − ' . Sueldo::soles( $f->montos['descuentos'] ) ) . '</span>' : ''; ?></td>
						<td><?php self::etiquetaRecibo( $r, $f ); ?></td>
						<td><span class="pk-chip <?php echo PrePlanilla::LISTO === $estado ? 'ok' : 'error'; ?>"><?php echo esc_html( PrePlanilla::ESTADOS[ $estado ] ); ?></span></td>
						<td><?php self::casillaPagado( $p, $mes, $estado, $pago ); ?></td>
						<td>
							<details>
								<summary class="pk-resumen-clic">Ver cálculo</summary>
								<div class="pk-mt-s">
									<?php foreach ( $f->explicacion() as $linea ) : ?>
										<div><?php echo esc_html( $linea ); ?></div>
									<?php endforeach; ?>
									<?php if ( $r ) : ?>
										<div>Recibo <?php echo esc_html( $r['numero'] . ' por ' . Sueldo::soles( (int) $r['monto_centimos'] ) ); ?>: <?php echo esc_html( ValidacionRecibo::textoComparacion( (int) $r['monto_centimos'], $f->montos['total'] ) ); ?>.</div>
									<?php endif; ?>
									<a href="<?php echo esc_url( PantallaInforme::url( $id, $mes ) ); ?>">Ver sus días</a>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<div class="pk-caja pk-fila pk-max pk-mt-s">
				<strong><?php echo $listos ? esc_html( 'Ya puedes pagar a ' . PantallaResumen::plural( $listos, 'persona', 'personas' ) . '.' ) : 'No hay pagos listos pendientes.'; ?></strong>
				<a class="button button-primary" href="<?php echo esc_url( Menu::urlAccion( 'pka_planilla_listos', array( 'mes' => $mes, 'solo' => 'listos' ) ) ); ?>">Planilla de las listas</a>
				<a class="button" href="<?php echo esc_url( Menu::urlAccion( 'pka_planilla_listos', array( 'mes' => $mes ) ) ); ?>">Planilla de todas</a>
			</div>
		</div>
		<?php
	}

	/** "Recibido · Coincide", "Por revisar · Difiere S/ 12.00", "Falta". */
	private static function etiquetaRecibo( ?array $r, PrePlanilla $f ): void {
		if ( ! $r ) {
			echo '<span class="description">Falta</span>';
			return;
		}

		$texto = array(
			ValidacionRecibo::RECIBIDO  => 'Recibido',
			ValidacionRecibo::ENVIADO   => 'Por revisar',
			ValidacionRecibo::OBSERVADO => 'Observado',
		)[ $r['estado'] ] ?? $r['estado'];
		$comp  = $f->sueldoFinal ? ValidacionRecibo::textoComparacion( (int) $r['monto_centimos'], $f->montos['total'] ) : '';

		echo esc_html( $texto );

		if ( '' !== $comp ) {
			echo '<br><span class="' . ( 'Coincide' === $comp ? 'pk-txt-ok' : 'pk-txt-aviso' ) . '">' . esc_html( $comp ) . '</span>';
		}
	}

	private static function casillaPagado( array $p, string $mes, string $estado, ?array $pago ): void {
		$id = (int) $p['id'];

		if ( $pago ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_pago_desmarcar">
				<input type="hidden" name="trabajador" value="<?php echo esc_attr( (string) $id ); ?>">
				<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
				<?php wp_nonce_field( 'pka_pago_desmarcar' ); ?>
				<span class="pk-txt-ok pk-negrita">✔ Pagado</span><br>
				<span class="description"><?php echo esc_html( \Pekokis\Asistencia\WordPress\Ajustes::local( $pago['marcado_en'], 'd/m' ) . ' · ' . Sueldo::soles( (int) $pago['total_centimos'] ) ); ?></span><br>
				<button class="button-link pk-txt-error">Desmarcar</button>
			</form>
			<?php
			return;
		}

		if ( PrePlanilla::LISTO !== $estado ) {
			echo '<span class="description" title="Resuelve primero lo que falta">—</span>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_pago_marcar">
			<input type="hidden" name="trabajador" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
			<?php wp_nonce_field( 'pka_pago_marcar' ); ?>
			<button class="button">Marcar pagado</button>
		</form>
		<?php
	}

	/** @param array<int, array<string, mixed>> $personas */
	private static function enlaces( array $personas, string $mes, bool $aFicha = false ): string {
		return implode(
			', ',
			array_map(
				static fn( array $p ): string => '<a href="' . esc_url( $aFicha ? admin_url( 'admin.php?page=pka-trabajadores&vista=editar&id=' . (int) $p['id'] . '#remuneracion' ) : PantallaInforme::url( (int) $p['id'], $mes ) ) . '">' . esc_html( $p['nombres'] . ' ' . $p['apellidos'] ) . '</a>',
				$personas
			)
		);
	}

	public static function marcar(): void {
		self::exigir( 'pka_pago_marcar' );

		[ $id, $mes ] = self::personaYMes();
		$t            = \Pekokis\Asistencia\WordPress\Trabajadores::buscar( $id );
		$f            = $t ? ( Jornadas::prePlanilla( array( $t ), $mes )[ $id ] ?? null ) : null;

		if ( ! $f || PrePlanilla::LISTO !== $f->estado() ) {
			Avisos::agregar( 'error', 'Ese pago todavía no está listo: resuelve primero lo que falta.' );
			Menu::volverA( self::url( $mes ) );
		}

		$result = Pagos::marcar( $id, $mes, $f->montos['total'] );
		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : 'Pago marcado: ' . \Pekokis\Asistencia\WordPress\Trabajadores::nombreCompleto( $t ) . ', ' . Sueldo::soles( $f->montos['total'] ) . '.' ) );
		Menu::volverA( self::url( $mes ) );
	}

	public static function desmarcar(): void {
		self::exigir( 'pka_pago_desmarcar' );

		[ $id, $mes ] = self::personaYMes();
		$result       = Pagos::desmarcar( $id, $mes, 'Desmarcado en la calculadora de pagos' );

		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : 'Pago desmarcado.' ) );
		Menu::volverA( self::url( $mes ) );
	}

	public static function planillaListos(): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_planilla_listos' );

		$mes   = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : '';
		$solo  = isset( $_GET['solo'] ) && 'listos' === $_GET['solo'];

		if ( ! in_array( $mes, Exportes::meses(), true ) ) {
			wp_die( 'Mes no válido.', 400 );
		}

		$xls = PrePlanillas::excel( $mes, wp_get_current_user()->display_name, $solo );
		Auditoria::registrar( 'preplanilla.descargar', 'registro', null, null, array( 'mes' => $mes, 'trabajadores' => $xls['trabajadores'], 'formato' => $solo ? 'Solo las listas para pagar' : 'Todas' ) );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $xls['nombre'] . '"' );
		header( 'Content-Length: ' . strlen( $xls['contenido'] ) );
		echo $xls['contenido']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- archivo binario.
		exit;
	}

	/** @return array{0: int, 1: string} */
	private static function personaYMes(): array {
		$id  = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado en exigir().
		$mes = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $mes, Exportes::meses(), true ) ) {
			wp_die( 'Mes no válido.', 400 );
		}

		return array( $id, $mes );
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
