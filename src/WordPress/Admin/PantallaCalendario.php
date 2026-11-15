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
 * Asistencia → Calendario: feriados y días no laborables de la empresa.
 */
final class PantallaCalendario {
	public const SLUG = 'pka-calendario';

	public static function registrar(): void {
		add_action( 'admin_post_pka_calendario_agregar', array( self::class, 'agregar' ) );
		add_action( 'admin_post_pka_calendario_quitar', array( self::class, 'quitar' ) );
		add_action( 'admin_post_pka_calendario_cargar', array( self::class, 'cargar' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$actual = (int) wp_date( 'Y', null, Ajustes::zona() );
		$anio   = isset( $_GET['anio'] ) ? max( $actual - 5, min( $actual + 2, absint( $_GET['anio'] ) ) ) : $actual; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dias   = Calendario::delAnio( $anio );
		$faltan = array_diff_key( FeriadosPeru::del( $anio ), $dias );
		$base   = admin_url( 'admin.php?page=' . self::SLUG );
		$zona   = Ajustes::zona();
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Calendario <?php echo (int) $anio; ?></h1>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'anio', $anio - 1, $base ) ); ?>">← <?php echo (int) ( $anio - 1 ); ?></a> ·
				<a href="<?php echo esc_url( add_query_arg( 'anio', $anio + 1, $base ) ); ?>"><?php echo (int) ( $anio + 1 ); ?> →</a>
			</p>
			<p>En los feriados y días no laborables nadie aparece como ausente ni con falta. Si alguien trabaja ese día, sus marcas se registran igual.</p>

			<?php if ( $faltan ) : ?>
				<div class="notice notice-warning inline" style="padding:8px 12px">
					<p><strong>Faltan <?php echo count( $faltan ); ?> feriados nacionales de <?php echo (int) $anio; ?></strong> según la lista sugerida:
						<?php echo esc_html( implode( ', ', array_map( static fn( $f, $n ) => wp_date( 'j M', strtotime( $f . ' 12:00' ), $zona ) . ' (' . $n . ')', array_keys( $faltan ), $faltan ) ) ); ?>.</p>
					<p>Revísala contra el calendario oficial antes de cargarla: los feriados cambian por ley.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pka_calendario_cargar">
						<input type="hidden" name="anio" value="<?php echo (int) $anio; ?>">
						<?php wp_nonce_field( 'pka_calendario_cargar' ); ?>
						<button class="button button-primary">Cargar los feriados de <?php echo (int) $anio; ?></button>
					</form>
				</div>
			<?php endif; ?>

			<h2>Agregar un día</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
				<input type="hidden" name="action" value="pka_calendario_agregar">
				<?php wp_nonce_field( 'pka_calendario_agregar' ); ?>
				<p style="margin:0"><label for="pka-c-fecha">Fecha</label><br><input type="date" id="pka-c-fecha" name="fecha" required></p>
				<p style="margin:0"><label for="pka-c-nombre">Nombre</label><br><input type="text" id="pka-c-nombre" name="nombre" class="regular-text" required placeholder="Por ejemplo: Aniversario de Pekokis"></p>
				<p style="margin:0">
					<label for="pka-c-tipo">Tipo</label><br>
					<select id="pka-c-tipo" name="tipo">
						<?php foreach ( Calendario::TIPOS as $clave => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="margin:0"><button class="button">Agregar</button></p>
			</form>

			<h2 style="margin-top:24px">Días de <?php echo (int) $anio; ?> (<?php echo count( $dias ); ?>)</h2>
			<table class="widefat striped" style="max-width:760px">
				<thead><tr><th>Fecha</th><th>Nombre</th><th>Tipo</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $dias ) : ?>
					<tr><td colspan="4">No hay días cargados para este año.</td></tr>
				<?php endif; ?>
				<?php foreach ( $dias as $fecha => $dia ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'D j \d\e F', ( new DateTimeImmutable( $fecha . ' 12:00', $zona ) )->getTimestamp(), $zona ) ); ?></td>
						<td><?php echo esc_html( $dia['nombre'] ); ?></td>
						<td><?php echo esc_html( Calendario::TIPOS[ $dia['tipo'] ] ?? $dia['tipo'] ); ?></td>
						<td><a href="<?php echo esc_url( Menu::urlAccion( 'pka_calendario_quitar', array( 'fecha' => $fecha ) ) ); ?>" onclick="return confirm('¿Quitar este día del calendario?')">Quitar</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
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
