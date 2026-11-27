<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\ValidacionConstancia;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Constancias;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Recibos por honorarios → Constancias de retención (pedido de gerencia,
 * 0.23.0). Una fila por persona activa con su última constancia de 4.ª o 5.ª:
 * hasta cuándo vale, el PDF, y Recibida u Observar, igual que los recibos.
 */
final class PantallaConstancias {
	public static function registrar(): void {
		add_action( 'admin_post_pka_constancia_revisar', array( self::class, 'revisar' ) );
		add_action( 'admin_post_pka_constancia_pdf', array( self::class, 'pdf' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => PantallaRecibos::SLUG, 'vista' => 'constancias' ) + $args, admin_url( 'admin.php' ) );
	}

	/** Pestañas de la página de recibos. */
	public static function pestanas( string $activa ): void {
		$porRevisar = Constancias::porRevisar();
		?>
		<nav class="pk-pestanas" aria-label="Recibos y constancias">
			<a href="<?php echo esc_url( PantallaRecibos::url() ); ?>" class="<?php echo 'recibos' === $activa ? 'activa' : ''; ?>">Recibos por mes</a>
			<a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo 'constancias' === $activa ? 'activa' : ''; ?>">Constancias de retención<?php echo $porRevisar ? ' <span class="pk-cuenta">' . (int) $porRevisar . '</span>' : ''; ?></a>
		</nav>
		<?php
	}

	public static function pintar(): void {
		$hoy      = wp_date( 'Y-m-d', null, Ajustes::zona() );
		$actuales = Constancias::actuales();
		$personas = Trabajadores::listar();
		?>
		<h1>Constancias de retención</h1>
		<?php self::pestanas( 'constancias' ); ?>
		<p class="description">La constancia de suspensión de retenciones de 4.ª categoría (o el documento de 5.ª) la sube cada trabajador desde la app, en "Mi recibo". Se revisa como los recibos. El panel avisa desde <?php echo (int) ValidacionConstancia::AVISO_DIAS; ?> días antes de que venza.</p>
		<div class="pk-desplaza">
			<table class="widefat striped">
				<thead><tr><th>Persona</th><th>Constancia</th><th>Vale hasta</th><th>Estado</th><th>Revisar</th></tr></thead>
				<tbody>
				<?php if ( ! $personas ) : ?>
					<tr><td colspan="5">No hay trabajadores activos.</td></tr>
				<?php endif; ?>
				<?php foreach ( $personas as $t ) : ?>
					<?php
					$id  = (int) $t['id'];
					$c   = $actuales[ $id ] ?? null;
					$vig = $c ? ValidacionConstancia::vigencia( (string) $c['vigente_hasta'], $hoy ) : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></strong></td>
						<td>
							<?php if ( $c ) : ?>
								<?php echo esc_html( ValidacionConstancia::TIPOS[ $c['tipo'] ] ?? $c['tipo'] ); ?><?php echo '' !== (string) $c['numero'] ? ' · ' . esc_html( $c['numero'] ) : ''; ?><br>
								<a href="<?php echo esc_url( Menu::urlAccion( 'pka_constancia_pdf', array( 'id' => (int) $c['id'] ) ) ); ?>" target="_blank" rel="noopener">Ver PDF</a>
								<span class="description">· enviada el <?php echo esc_html( Ajustes::local( (string) $c['enviado_en'], 'd/m/Y' ) ); ?></span>
							<?php else : ?>
								<span class="description">No envió constancia</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $c ) : ?>
								<span class="pk-chip <?php echo esc_attr( ValidacionConstancia::VENCIDA === $vig ? 'error' : ( ValidacionConstancia::POR_VENCER === $vig ? 'aviso' : 'ok' ) ); ?>"><?php echo esc_html( ValidacionConstancia::textoVigencia( (string) $c['vigente_hasta'], $hoy ) ); ?></span>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $c ? ( ValidacionRecibo::ENVIADO === $c['estado'] ? 'Por revisar' : ValidacionRecibo::ESTADOS[ $c['estado'] ] ) : '—' ); ?>
							<?php echo $c && ValidacionRecibo::OBSERVADO === $c['estado'] ? '<br><em>' . esc_html( $c['comentario'] ) . '</em>' : ''; ?>
						</td>
						<td>
							<?php if ( $c && ValidacionRecibo::ENVIADO === $c['estado'] && ! Trabajadores::esPropio( $id ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
									<input type="hidden" name="action" value="pka_constancia_revisar">
									<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
									<?php wp_nonce_field( 'pka_constancia_revisar' ); ?>
									<button class="button button-primary" name="decision" value="recibir">Recibida</button>
									<label class="screen-reader-text" for="pka-cobs-<?php echo (int) $c['id']; ?>">Observación</label>
									<input type="text" id="pka-cobs-<?php echo (int) $c['id']; ?>" name="comentario" placeholder="Qué hay que corregir">
									<button class="button" name="decision" value="observar">Observar</button>
								</form>
							<?php elseif ( $c && $c['revisado_en'] ) : ?>
								<span class="description"><?php echo esc_html( Ajustes::local( (string) $c['revisado_en'], 'd/m/Y' ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Para la ficha: la última constancia de la persona, en una línea. */
	public static function resumen( int $trabajadorId ): string {
		$c = Constancias::actual( $trabajadorId );

		if ( ! $c ) {
			return 'Sin constancia de retención.';
		}

		$hoy = wp_date( 'Y-m-d', null, Ajustes::zona() );

		return ( ValidacionConstancia::TIPOS[ $c['tipo'] ] ?? $c['tipo'] ) . ': ' . ( ValidacionRecibo::ENVIADO === $c['estado'] ? 'por revisar' : mb_strtolower( ValidacionRecibo::ESTADOS[ $c['estado'] ] ) ) . ' · ' . ValidacionConstancia::textoVigencia( (string) $c['vigente_hasta'], $hoy ) . '.';
	}

	public static function revisar(): void {
		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_constancia_revisar' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';
		$comentario = isset( $_POST['comentario'] ) ? sanitize_text_field( wp_unslash( $_POST['comentario'] ) ) : '';
		$r          = 'observar' === $decision ? Constancias::observar( $id, $comentario ) : Constancias::recibir( $id );

		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : ( 'observar' === $decision ? 'Constancia observada: se le avisa al trabajador.' : 'Constancia marcada como recibida.' ) ) );
		Menu::volverA( self::url() );
	}

	public static function pdf(): void {
		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_constancia_pdf' );

		$c    = Constancias::buscar( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$ruta = $c ? Constancias::ruta( $c ) : '';

		if ( ! $c || ! is_file( $ruta ) ) {
			wp_die( 'No se encontró el archivo.', 404 );
		}

		Auditoria::registrar( 'constancia.ver', 'trabajador', (int) $c['trabajador_id'], null, array( 'tipo' => ValidacionConstancia::TIPOS[ $c['tipo'] ] ?? $c['tipo'], 'hasta' => $c['vigente_hasta'] ) );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="constancia-' . sanitize_file_name( $c['tipo'] . '-' . $c['vigente_hasta'] ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $ruta ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
