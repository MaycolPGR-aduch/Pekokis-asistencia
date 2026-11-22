<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\FeriadosPeru;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Calendario;
use Pekokis\Asistencia\WordPress\Capacidades;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Feriados y calendario (maqueta G9): feriados nacionales y días
 * no laborables de la empresa, con su tipo, la regla de pago y si ya pasaron.
 */
final class PantallaCalendario {
	public const SLUG = 'pka-calendario';

	public static function registrar(): void {
		add_action( 'admin_post_pka_calendario_agregar', array( self::class, 'agregar' ) );
		add_action( 'admin_post_pka_calendario_editar', array( self::class, 'editar' ) );
		add_action( 'admin_post_pka_calendario_quitar', array( self::class, 'quitar' ) );
		add_action( 'admin_post_pka_calendario_cargar', array( self::class, 'cargar' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$zona    = Ajustes::zona();
		$hoy     = wp_date( 'Y-m-d', null, $zona );
		$actual  = (int) substr( $hoy, 0, 4 );
		$anio    = isset( $_GET['anio'] ) ? max( $actual - 5, min( $actual + 2, absint( $_GET['anio'] ) ) ) : $actual; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editar  = isset( $_GET['editar'] ) ? sanitize_text_field( wp_unslash( $_GET['editar'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dias    = Calendario::delAnio( $anio );
		$faltan  = array_diff_key( FeriadosPeru::del( $anio ), $dias );
		$base    = admin_url( 'admin.php?page=' . self::SLUG );
		$fechaTx = static fn( string $f, string $formato ): string => wp_date( $formato, ( new DateTimeImmutable( $f . ' 12:00', $zona ) )->getTimestamp(), $zona );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Feriados y calendario <span class="pk-h1-der"><?php echo (int) $anio; ?></span></h1>

			<p class="pk-fila espacio pk-mt-s">
				<a class="button" href="<?php echo esc_url( add_query_arg( 'anio', $anio - 1, $base ) ); ?>" aria-label="Año anterior">‹ <?php echo (int) ( $anio - 1 ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'anio', $anio + 1, $base ) ); ?>" aria-label="Año siguiente"><?php echo (int) ( $anio + 1 ); ?> ›</a>
				<a class="button button-primary" href="#pka-agregar">+ Agregar feriado</a>
			</p>

			<?php if ( $faltan ) : ?>
				<div class="notice notice-warning inline">
					<p><strong>Faltan <?php echo count( $faltan ); ?> feriados nacionales de <?php echo (int) $anio; ?></strong> según la lista sugerida:
						<?php echo esc_html( implode( ', ', array_map( static fn( $f, $n ) => $fechaTx( $f, 'j M' ) . ' (' . $n . ')', array_keys( $faltan ), $faltan ) ) ); ?>.</p>
					<p>Revísala contra el calendario oficial antes de cargarla: los feriados cambian por ley.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pka_calendario_cargar">
						<input type="hidden" name="anio" value="<?php echo (int) $anio; ?>">
						<?php wp_nonce_field( 'pka_calendario_cargar' ); ?>
						<button class="button button-primary">Cargar los feriados de <?php echo (int) $anio; ?></button>
					</form>
				</div>
			<?php endif; ?>

			<div class="pk-desplaza">
				<table class="widefat striped pk-max-m">
					<thead><tr><th>Fecha</th><th>Nombre</th><th>Tipo</th><th>Regla de pago</th><th>Estado</th><th></th></tr></thead>
					<tbody>
					<?php if ( ! $dias ) : ?>
						<tr><td colspan="6">No hay días cargados para <?php echo (int) $anio; ?>.</td></tr>
					<?php endif; ?>
					<?php foreach ( $dias as $fecha => $dia ) : ?>
						<?php
						$nacional = 'feriado' === $dia['tipo'];
						$estado   = $fecha < $hoy ? array( 'Pasado', 'gris' ) : ( $fecha === $hoy ? array( 'Hoy', 'ok' ) : array( 'Próximo', 'aviso' ) );
						?>
						<tr>
							<td class="pk-nowrap" title="<?php echo esc_attr( $fechaTx( $fecha, 'l j \d\e F' ) ); ?>"><?php echo esc_html( $fechaTx( $fecha, 'j M' ) ); ?></td>
							<?php if ( $editar === $fecha ) : ?>
								<td colspan="5">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila">
										<input type="hidden" name="action" value="pka_calendario_editar">
										<input type="hidden" name="fecha" value="<?php echo esc_attr( $fecha ); ?>">
										<?php wp_nonce_field( 'pka_calendario_editar' ); ?>
										<label class="screen-reader-text" for="pka-cal-nombre">Nombre</label>
										<input type="text" id="pka-cal-nombre" name="nombre" class="regular-text" required value="<?php echo esc_attr( $dia['nombre'] ); ?>">
										<label class="screen-reader-text" for="pka-cal-tipo">Tipo</label>
										<select id="pka-cal-tipo" name="tipo">
											<?php foreach ( Calendario::TIPOS as $clave => $etiqueta ) : ?>
												<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $dia['tipo'], $clave ); ?>><?php echo esc_html( $etiqueta ); ?></option>
											<?php endforeach; ?>
										</select>
										<button class="button button-primary">Guardar</button>
										<a class="button-link" href="<?php echo esc_url( add_query_arg( 'anio', $anio, $base ) ); ?>">Cancelar</a>
										<a class="button-link pk-txt-error" href="<?php echo esc_url( Menu::urlAccion( 'pka_calendario_quitar', array( 'fecha' => $fecha ) ) ); ?>" onclick="return confirm('¿Quitar este día del calendario?')">Quitar del calendario</a>
									</form>
								</td>
							<?php else : ?>
								<td class="pk-ancho"><strong><?php echo esc_html( $dia['nombre'] ); ?></strong></td>
								<td><span class="pk-chip <?php echo $nacional ? 'info' : 'gris'; ?>"><?php echo $nacional ? 'Nacional' : 'Del taller'; ?></span></td>
								<td><span class="pk-chip <?php echo $nacional ? 'ok' : 'gris'; ?>" title="Si alguien trabaja ese día, se le paga con su tarifa de feriado (Sueldos y tarifas)."><?php echo $nacional ? 'Según ley' : 'No se trabaja'; ?></span></td>
								<td><span class="pk-chip <?php echo esc_attr( $estado[1] ); ?>"><?php echo esc_html( $estado[0] ); ?></span></td>
								<td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'anio' => $anio, 'editar' => $fecha ), $base ) ); ?>">Editar</a></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="description pk-mt-s">En los feriados y días no laborables nadie aparece como ausente ni con falta. Si alguien trabaja ese día, sus marcas se registran igual y la calculadora le paga esas horas con su tarifa de feriado (se define en Sueldos y tarifas). Conviene confirmar la regla con el contador.</p>

			<h2 class="pk-mt" id="pka-agregar">Agregar un feriado o un día del taller</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila abajo">
				<input type="hidden" name="action" value="pka_calendario_agregar">
				<?php wp_nonce_field( 'pka_calendario_agregar' ); ?>
				<p class="pk-m0"><label for="pka-c-fecha">Fecha</label><br><input type="date" id="pka-c-fecha" name="fecha" required></p>
				<p class="pk-m0"><label for="pka-c-nombre">Nombre</label><br><input type="text" id="pka-c-nombre" name="nombre" class="regular-text" required placeholder="Por ejemplo: Cierre del taller"></p>
				<p class="pk-m0">
					<label for="pka-c-tipo">Tipo</label><br>
					<select id="pka-c-tipo" name="tipo">
						<?php foreach ( Calendario::TIPOS as $clave => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="pk-m0"><button class="button">Agregar</button></p>
			</form>
		</div>
		<?php
	}

	public static function agregar(): void {
		self::exigir( 'pka_calendario_agregar' );

		$fecha  = isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '';
		$result = Calendario::agregar(
			$fecha,
			isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '',
			isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : ''
		);

		self::avisar( $result, 'Día agregado al calendario.' );
		Menu::volver( self::SLUG, array( 'anio' => (int) substr( $fecha, 0, 4 ) ) );
	}

	public static function editar(): void {
		self::exigir( 'pka_calendario_editar' );

		$fecha  = isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '';
		$result = Calendario::editar(
			$fecha,
			isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '',
			isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : ''
		);

		self::avisar( $result, 'Día actualizado.' );
		Menu::volver( self::SLUG, array( 'anio' => (int) substr( $fecha, 0, 4 ) ) );
	}

	public static function quitar(): void {
		self::exigir( 'pka_calendario_quitar' );

		$fecha = isset( $_GET['fecha'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha'] ) ) : '';

		self::avisar( Calendario::quitar( $fecha ), 'Día quitado del calendario.' );
		Menu::volver( self::SLUG, array( 'anio' => (int) substr( $fecha, 0, 4 ) ) );
	}

	public static function cargar(): void {
		self::exigir( 'pka_calendario_cargar' );

		$anio = isset( $_POST['anio'] ) ? absint( $_POST['anio'] ) : 0;
		$n    = $anio >= 2020 && $anio <= 2100 ? Calendario::cargarFeriadosPeru( $anio ) : 0;

		Avisos::agregar( 'success', esc_html( $n . ' feriados cargados para ' . $anio . '.' ) );
		Menu::volver( self::SLUG, array( 'anio' => $anio ) );
	}

	private static function avisar( mixed $result, string $exito ): void {
		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : $exito ) );
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
