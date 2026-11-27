<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Notificaciones;
use Pekokis\Asistencia\WordPress\Recibos;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Recibos (G11 y G11B de las maquetas): un recibo por honorarios
 * por persona y mes, comparado con el cálculo; se marca como recibido u
 * observado, se recuerda a quien falta y se ve el historial de cada persona.
 */
final class PantallaRecibos {
	public const SLUG = 'pka-recibos';

	private const FILTROS = array(
		''          => 'Todos',
		'falta'     => 'Pendientes',
		'enviado'   => 'Por revisar',
		'recibido'  => 'Recibidos',
		'observado' => 'Observados',
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_recibo_revisar', array( self::class, 'revisar' ) );
		add_action( 'admin_post_pka_recibo_recordar', array( self::class, 'recordar' ) );
		add_action( 'admin_post_pka_recibo_pdf', array( self::class, 'pdf' ) );
	}

	public static function url( string $mes = '', array $args = array() ): string {
		return add_query_arg( array_filter( array( 'page' => self::SLUG, 'mes' => $mes ) + $args ), admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$persona = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0;
		$meses   = array_slice( Exportes::meses(), 0, 6 );
		$mes     = isset( $_GET['mes'] ) && in_array( $_GET['mes'], Exportes::meses(), true ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ( $meses[1] ?? $meses[0] );
		$filtro  = isset( $_GET['estado'] ) && isset( self::FILTROS[ $_GET['estado'] ] ) ? sanitize_key( $_GET['estado'] ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		Avisos::mostrar();

		if ( $persona ) {
			self::historial( $persona );
			echo '</div>';
			return;
		}

		if ( isset( $_GET['vista'] ) && 'constancias' === $_GET['vista'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			PantallaConstancias::pintar();
			echo '</div>';
			return;
		}

		$datos    = Informes::mes( $mes, null );
		$recibos  = Recibos::delMes( $mes );
		$personas = $datos['personas'];

		usort( $personas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );
		?>
		<h1>Recibos por honorarios</h1>
		<?php PantallaConstancias::pestanas( 'recibos' ); ?>
		<nav class="nav-tab-wrapper pk-mb">
			<?php foreach ( $meses as $m ) : ?>
				<a class="nav-tab <?php echo $m === $mes ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $m ) ); ?>"><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></a>
			<?php endforeach; ?>
		</nav>
		<ul class="subsubsub pk-sin-flotar">
			<?php foreach ( self::FILTROS as $clave => $etiqueta ) : ?>
				<li><a href="<?php echo esc_url( self::url( $mes, array( 'estado' => $clave ) ) ); ?>" <?php echo $clave === $filtro ? 'class="current"' : ''; ?>><?php echo esc_html( $etiqueta ); ?></a><?php echo 'observado' !== $clave ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="description">Cada recibo se compara con el cálculo del mes (Asistencia → Pagos). El trabajador ve su propio monto, nunca el cálculo.</p>

		<table class="pk-tabla-recibos widefat striped">
			<thead><tr><th>Persona</th><th>Recibo</th><th>Monto</th><th>Cálculo del mes</th><th>Estado</th><th>Revisar</th><th>Enviados</th></tr></thead>
			<tbody>
			<?php
			$mostrados = 0;
			foreach ( $personas as $p ) :
				$id     = (int) $p['id'];
				$r      = $recibos[ $id ] ?? null;
				$estado = $r ? $r['estado'] : 'falta';

				if ( '' !== $filtro && $filtro !== $estado ) {
					continue;
				}

				++$mostrados;
				$f = $datos['filas'][ $id ] ?? new PrePlanilla();
				?>
				<tr>
					<td><strong><?php echo esc_html( $p['apellidos'] . ', ' . $p['nombres'] ); ?></strong></td>
					<td>
						<?php if ( $r ) : ?>
							<?php echo esc_html( $r['numero'] ); ?><br>
							<a href="<?php echo esc_url( Menu::urlAccion( 'pka_recibo_pdf', array( 'id' => (int) $r['id'] ) ) ); ?>" target="_blank" rel="noopener">Ver PDF</a>
							<span class="description">· <?php echo esc_html( Ajustes::local( $r['enviado_en'], 'd/m H:i' ) ); ?></span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><?php echo $r ? esc_html( Sueldo::soles( (int) $r['monto_centimos'] ) ) : '—'; ?></td>
					<td>
						<?php if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) : ?>
							<span class="description">Lo ve quien ve los pagos</span>
						<?php else : ?>
						<?php echo $f->sueldoFinal ? esc_html( Sueldo::soles( $f->montos['total'] ) ) : 'Sin honorario'; ?>
						<?php endif; ?>
						<?php if ( $r && $f->sueldoFinal && current_user_can( Capacidades::VER_SUELDOS ) ) : ?>
							<?php $comp = ValidacionRecibo::textoComparacion( (int) $r['monto_centimos'], $f->montos['total'] ); ?>
							<br><strong class="<?php echo 'Coincide' === $comp ? 'pk-txt-ok' : 'pk-txt-aviso'; ?>"><?php echo esc_html( $comp ); ?></strong>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $r ? ( ValidacionRecibo::ENVIADO === $estado ? 'Por revisar' : ValidacionRecibo::ESTADOS[ $estado ] ) : 'Pendiente' ); ?>
						<?php echo $r && ValidacionRecibo::OBSERVADO === $estado ? '<br><em>' . esc_html( $r['comentario'] ) . '</em>' : ''; ?>
					</td>
					<td>
						<?php if ( $r && ValidacionRecibo::ENVIADO === $estado && ! Trabajadores::esPropio( $id ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
								<input type="hidden" name="action" value="pka_recibo_revisar">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $r['id'] ); ?>">
								<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
								<?php wp_nonce_field( 'pka_recibo_revisar' ); ?>
								<button class="button button-primary" name="decision" value="recibir">Recibido</button>
								<label class="screen-reader-text" for="pka-obs-<?php echo esc_attr( (string) $r['id'] ); ?>">Observación</label>
								<input type="text" id="pka-obs-<?php echo esc_attr( (string) $r['id'] ); ?>" name="comentario" placeholder="Qué hay que corregir">
								<button class="button" name="decision" value="observar">Observar</button>
							</form>
						<?php elseif ( ! $r || ValidacionRecibo::OBSERVADO === $estado ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="pka_recibo_recordar">
								<input type="hidden" name="trabajador" value="<?php echo esc_attr( (string) $id ); ?>">
								<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
								<?php wp_nonce_field( 'pka_recibo_recordar' ); ?>
								<button class="button">Recordar</button>
							</form>
						<?php else : ?>
							<span class="description"><?php echo esc_html( Ajustes::local( (string) $r['revisado_en'], 'd/m' ) ); ?></span>
						<?php endif; ?>
					</td>
					<td><a href="<?php echo esc_url( self::url( '', array( 'trabajador' => $id ) ) ); ?>">Enviados (<?php echo (int) ( $r['enviados'] ?? count( Recibos::historial( $id ) ) ); ?>)</a></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $mostrados ) : ?>
				<tr><td colspan="7">No hay recibos en este filtro.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<p class="description">"Recordar" le manda un aviso por correo o WhatsApp (si tiene correo o hay webhook de avisos), aunque los avisos automáticos estén apagados.</p>
		</div>
		<?php
	}

	/** G11B: todos los recibos de una persona, mes por mes. */
	private static function historial( int $id ): void {
		$t = Trabajadores::buscar( $id );

		if ( ! $t ) {
			echo '<p>No existe ese trabajador.</p>';
			return;
		}
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Volver a los recibos</a></p>
		<h1>Recibos de <?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></h1>
		<table class="widefat striped pk-max">
			<thead><tr><th>Mes</th><th>Recibo</th><th>Monto</th><th>Estado</th><th>Enviado</th><th>Revisado</th></tr></thead>
			<tbody>
			<?php $filas = Recibos::historial( $id ); ?>
			<?php if ( ! $filas ) : ?>
				<tr><td colspan="6">Todavía no envió ningún recibo.</td></tr>
			<?php endif; ?>
			<?php foreach ( $filas as $r ) : ?>
				<tr<?php echo ValidacionRecibo::ANULADO === $r['estado'] ? ' class="pk-atenuado"' : ''; ?>>
					<td><?php echo esc_html( PantallaResumen::nombreMes( $r['mes'] ) ); ?></td>
					<td><?php echo esc_html( $r['numero'] ); ?> · <a href="<?php echo esc_url( Menu::urlAccion( 'pka_recibo_pdf', array( 'id' => (int) $r['id'] ) ) ); ?>" target="_blank" rel="noopener">PDF</a></td>
					<td><?php echo esc_html( Sueldo::soles( (int) $r['monto_centimos'] ) ); ?></td>
					<td><?php echo esc_html( ValidacionRecibo::ESTADOS[ $r['estado'] ] ?? $r['estado'] ); ?><?php echo '' !== $r['comentario'] ? '<br><em>' . esc_html( $r['comentario'] ) . '</em>' : ''; ?></td>
					<td><?php echo esc_html( Ajustes::local( $r['enviado_en'], 'd/m/Y H:i' ) ); ?></td>
					<td><?php echo esc_html( Ajustes::local( $r['revisado_en'], 'd/m/Y' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function revisar(): void {
		self::exigir( 'pka_recibo_revisar' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mes        = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';
		$comentario = isset( $_POST['comentario'] ) ? sanitize_text_field( wp_unslash( $_POST['comentario'] ) ) : '';
		$result     = 'observar' === $decision ? Recibos::observar( $id, $comentario ) : Recibos::recibir( $id );

		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : ( 'observar' === $decision ? 'Recibo observado: se le avisa al trabajador.' : 'Recibo marcado como recibido.' ) ) );
		Menu::volverA( self::url( $mes ) );
	}

	public static function recordar(): void {
		self::exigir( 'pka_recibo_recordar' );

		$id  = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$mes = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$n   = Notificaciones::recordarRecibo( $id, $mes );

		Avisos::agregar( $n ? 'success' : 'error', $n ? 'Recordatorio en camino (sale en los próximos minutos).' : 'No se pudo avisar: esa persona no tiene correo y no hay webhook de avisos (WhatsApp) configurado.' );
		Menu::volverA( self::url( $mes ) );
	}

	/** Descarga del PDF, solo para quien ve sueldos; queda en el historial. */
	public static function pdf(): void {
		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_recibo_pdf' );

		$r    = Recibos::buscar( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$ruta = $r ? Recibos::ruta( $r ) : '';

		if ( ! $r || ! is_file( $ruta ) ) {
			wp_die( 'No se encontró el archivo.', 404 );
		}

		Auditoria::registrar( 'recibo.ver', 'trabajador', (int) $r['trabajador_id'], null, array( 'mes' => $r['mes'], 'numero' => $r['numero'] ) );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="recibo-' . sanitize_file_name( $r['numero'] ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $ruta ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
