<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Descuentos;
use Pekokis\Asistencia\WordPress\Pagos;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha → Pagos y recibos (pedido de gerencia, 0.22.0):
 * - Descuentos del mes: monto libre y motivo; se restan del total. Se anulan con motivo.
 * - Historial de pagos de la persona: cada mes marcado como pagado, con su total.
 */
final class PantallaDescuentos {
	public static function registrar(): void {
		add_action( 'admin_post_pka_descuento_crear', array( self::class, 'crear' ) );
		add_action( 'admin_post_pka_descuento_anular', array( self::class, 'anular' ) );
	}

	/** @param array<string, mixed> $t */
	public static function caja( array $t, string $mes ): void {
		$id     = (int) $t['id'];
		$filas  = Descuentos::deTrabajador( $id, $mes );
		$total  = array_sum( array_map( static fn( array $d ): int => 'vigente' === $d['estado'] ? (int) $d['monto_centimos'] : 0, $filas ) );
		$propio = Trabajadores::esPropio( $id );
		?>
		<h2>Descuentos de <?php echo esc_html( PantallaResumen::nombreMes( $mes ) ); ?></h2>
		<p class="description">Un monto libre con su motivo (adelanto, préstamo, daño…). Se resta del total del mes. No se edita: se anula con motivo.</p>
		<?php if ( $filas ) : ?>
			<ul class="pk-sin-lista pk-descuentos">
				<?php foreach ( $filas as $d ) : ?>
					<li class="<?php echo 'anulado' === $d['estado'] ? 'pk-atenuado' : ''; ?>">
						<strong><?php echo esc_html( '− ' . Sueldo::soles( (int) $d['monto_centimos'] ) ); ?></strong> · <?php echo esc_html( $d['motivo'] ); ?>
						<?php if ( 'anulado' === $d['estado'] ) : ?>
							<br><em>Anulado: <?php echo esc_html( $d['motivo_anulacion'] ); ?></em>
						<?php elseif ( ! $propio ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta pk-mt-xs">
								<input type="hidden" name="action" value="pka_descuento_anular">
								<input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
								<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
								<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
								<?php wp_nonce_field( 'pka_descuento_anular' ); ?>
								<label class="screen-reader-text" for="pka-da-<?php echo (int) $d['id']; ?>">Motivo de la anulación</label>
								<input type="text" id="pka-da-<?php echo (int) $d['id']; ?>" name="motivo" required placeholder="Por qué se anula">
								<button class="button">Anular</button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><strong>Total a descontar: <?php echo esc_html( Sueldo::soles( $total ) ); ?></strong></p>
		<?php else : ?>
			<p>Sin descuentos este mes.</p>
		<?php endif; ?>

		<?php if ( $propio ) : ?>
			<p><em>Es tu propio pago: los descuentos los registra otra persona de gerencia.</em></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-mt-s">
				<input type="hidden" name="action" value="pka_descuento_crear">
				<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
				<?php wp_nonce_field( 'pka_descuento_crear' ); ?>
				<p class="pk-fila">
					<label class="screen-reader-text" for="pka-d-monto">Monto (S/)</label>
					<span class="pk-soles">S/ <input type="text" inputmode="decimal" id="pka-d-monto" name="monto" class="pk-monto" required placeholder="0.00" autocomplete="off"></span>
					<label class="screen-reader-text" for="pka-d-motivo">Motivo</label>
					<input type="text" id="pka-d-motivo" name="motivo" class="pk-crece" required maxlength="255" placeholder="Motivo del descuento">
					<button class="button">Agregar descuento</button>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	/** @param array<string, mixed> $t */
	public static function historialPagos( array $t ): void {
		$id    = (int) $t['id'];
		$pagos = Pagos::historial( $id );
		?>
		<h2>Historial de pagos</h2>
		<?php if ( ! $pagos ) : ?>
			<p class="description">Todavía no tiene meses marcados como pagados. Se marcan en la <a href="<?php echo esc_url( PantallaPagos::url() ); ?>">Calculadora de pagos</a>.</p>
			<?php
			return;
		endif;
		?>
		<div class="pk-desplaza">
			<table class="widefat striped">
				<thead><tr><th>Mes</th><th>Total pagado</th><th>Descuentos</th><th>Marcado</th></tr></thead>
				<tbody>
				<?php foreach ( $pagos as $p ) : ?>
					<?php
					$quien = get_userdata( (int) $p['marcado_por'] );
					$desc  = Descuentos::totales( array( $id ), (string) $p['mes'] )[ $id ] ?? 0;
					?>
					<tr<?php echo 'pagado' !== $p['estado'] ? ' class="pk-atenuado"' : ''; ?>>
						<td><a href="<?php echo esc_url( PantallaFicha::url( $id, 'pagos', array( 'mes' => $p['mes'] ) ) ); ?>"><?php echo esc_html( ucfirst( PantallaResumen::nombreMes( (string) $p['mes'] ) ) ); ?></a></td>
						<td><strong><?php echo esc_html( Sueldo::soles( (int) $p['total_centimos'] ) ); ?></strong><?php echo 'pagado' !== $p['estado'] ? ' <span class="pk-chip gris">Desmarcado</span>' : ''; ?></td>
						<td><?php echo $desc ? esc_html( '− ' . Sueldo::soles( $desc ) ) : '—'; ?></td>
						<td class="pk-chico"><?php echo esc_html( \Pekokis\Asistencia\WordPress\Ajustes::local( (string) $p['marcado_en'], 'd/m/Y' ) . ( $quien ? ' · ' . $quien->display_name : '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function crear(): void {
		self::exigir( 'pka_descuento_crear' );

		$id     = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$mes    = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$monto  = isset( $_POST['monto'] ) ? sanitize_text_field( wp_unslash( $_POST['monto'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$r      = Descuentos::crear( $id, $mes, $monto, $motivo );

		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : 'Descuento registrado. Ya se resta del total del mes.' ) );
		Menu::volverA( PantallaFicha::url( $id, 'pagos', array( 'mes' => $mes ) ) );
	}

	public static function anular(): void {
		self::exigir( 'pka_descuento_anular' );

		$id     = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$mes    = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$r      = Descuentos::anular( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, $motivo );

		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : 'Descuento anulado.' ) );
		Menu::volverA( PantallaFicha::url( $id, 'pagos', array( 'mes' => $mes ) ) );
	}

	private static function exigir( string $nonce ): void {
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $nonce );
	}
}
