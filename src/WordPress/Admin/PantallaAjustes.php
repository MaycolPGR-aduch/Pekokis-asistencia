<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use InvalidArgumentException;
use Pekokis\Asistencia\Nucleo\ModoPresencia;
use Pekokis\Asistencia\Nucleo\RedAutorizada;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Diagnostico;
use Pekokis\Asistencia\WordPress\Pantallas;
use Pekokis\Asistencia\WordPress\Peticion;
use Pekokis\Asistencia\WordPress\Presencia;
use Pekokis\Asistencia\WordPress\Tablas;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Ajustes: modo de presencia y redes del WiFi del taller.
 */
final class PantallaAjustes {
	public const SLUG = 'pka-ajustes';

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_modo', array( self::class, 'guardarModo' ) );
		add_action( 'admin_post_pka_agregar_red', array( self::class, 'agregarRed' ) );
		add_action( 'admin_post_pka_cambiar_red', array( self::class, 'cambiarRed' ) );
		add_action( 'admin_post_pka_cambiar_pantalla', array( self::class, 'cambiarPantalla' ) );
	}

	/** Estado del sistema: sirve para comprobar el despliegue. */
	private static function diagnostico(): void {
		$puntos   = Diagnostico::revisar();
		$pendient = array_filter( $puntos, static fn( array $p ): bool => Diagnostico::BIEN !== $p['estado'] );
		$iconos   = array( Diagnostico::BIEN => '✔', Diagnostico::AVISO => '⚠', Diagnostico::PROBLEMA => '✘' );
		$colores  = array( Diagnostico::BIEN => '#00733a', Diagnostico::AVISO => '#b26200', Diagnostico::PROBLEMA => '#b32d2e' );
		?>
		<details <?php echo $pendient ? 'open' : ''; ?> style="background:#fff;border:1px solid #c3c4c7;padding:8px 16px;margin:12px 0 24px">
			<summary style="cursor:pointer;font-weight:600">
				Estado del sistema: <?php echo $pendient ? esc_html( count( $pendient ) . ' punto(s) por revisar' ) : '✔ todo en orden'; ?>
			</summary>
			<ul style="margin:12px 0">
				<?php foreach ( $puntos as $p ) : ?>
					<li>
						<span style="color:<?php echo esc_attr( $colores[ $p['estado'] ] ); ?>;font-weight:700" aria-hidden="true"><?php echo esc_html( $iconos[ $p['estado'] ] ); ?></span>
						<strong><?php echo esc_html( $p['tema'] ); ?></strong>
						<?php echo '' !== $p['detalle'] ? '— ' . esc_html( $p['detalle'] ) : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function pantallas(): void {
		$pantallas = Pantallas::listar();
		$limite    = gmdate( 'Y-m-d H:i:s', time() - Pantallas::SEGUNDOS_SIN_CONEXION );
		?>
		<h2>Pantallas del QR</h2>
		<p>
			Para activar una tablet: inicia sesión con tu usuario en esa tablet y abre
			<a href="<?php echo esc_url( home_url( '/pantalla/' ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( home_url( '/pantalla/' ) ); ?></code></a>.
			Después puedes cerrar la sesión: la pantalla sigue funcionando.
		</p>
		<table class="widefat striped">
			<thead><tr><th>Nombre</th><th>Última conexión</th><th>IP</th><th>Estado</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $pantallas ) : ?>
				<tr><td colspan="5">Todavía no hay pantallas. Sin ellas, la presencia solo se prueba con el WiFi del taller.</td></tr>
			<?php endif; ?>
			<?php foreach ( $pantallas as $p ) : ?>
				<?php $apagada = $p['activa'] && ( null === $p['ultima_conexion'] || $p['ultima_conexion'] < $limite ); ?>
				<tr>
					<td><?php echo esc_html( $p['nombre'] ); ?></td>
					<td><?php echo $p['ultima_conexion'] ? esc_html( get_date_from_gmt( $p['ultima_conexion'], 'd/m H:i' ) ) : '—'; ?><?php echo $apagada ? ' <strong style="color:#b32d2e">sin conexión</strong>' : ''; ?></td>
					<td><code><?php echo esc_html( $p['ultima_ip'] ); ?></code></td>
					<td><?php echo $p['activa'] ? 'Activa' : 'Desactivada'; ?></td>
					<td><a href="<?php echo esc_url( Menu::urlAccion( 'pka_cambiar_pantalla', array( 'id' => $p['id'] ) ) ); ?>"><?php echo $p['activa'] ? 'Desactivar' : 'Activar'; ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function cambiarPantalla(): void {
		self::exigir( 'pka_cambiar_pantalla' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		foreach ( Pantallas::listar() as $p ) {
			if ( (int) $p['id'] === $id ) {
				Pantallas::cambiarEstado( $id, ! $p['activa'] );
			}
		}

		Menu::volver( self::SLUG );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$modo     = Ajustes::modoPresencia();
		$ip       = Peticion::ip();
		$redes    = Presencia::filasRedes();
		$enLaRed  = Presencia::enRedDelTaller();
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Ajustes de asistencia</h1>

			<?php self::diagnostico(); ?>

			<h2>Cómo se prueba que están en el taller</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_guardar_modo">
				<?php wp_nonce_field( 'pka_guardar_modo' ); ?>
				<fieldset>
					<?php foreach ( ModoPresencia::cases() as $opcion ) : ?>
						<label style="display:block;margin:6px 0">
							<input type="radio" name="modo" value="<?php echo esc_attr( $opcion->value ); ?>" <?php checked( $modo->value, $opcion->value ); ?>>
							<?php echo esc_html( $opcion->etiqueta() ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">Empieza con "WiFi del taller o QR". Si detectas trampas, cambia a "WiFi del taller y QR". Si la IP del taller cambia mucho o es compartida, usa "Solo QR".</p>
				<?php submit_button( 'Guardar modo' ); ?>
			</form>

			<h2>Redes del WiFi del taller</h2>
			<p>
				Tu IP ahora: <code><?php echo esc_html( '' !== $ip ? $ip : 'desconocida' ); ?></code>
				<?php echo $enLaRed ? '— ✔ está autorizada.' : '— no está autorizada.'; ?>
			</p>
			<?php if ( '' !== $ip && RedAutorizada::esCgnat( $ip ) ) : ?>
				<div class="notice notice-warning inline"><p>Esta IP es <strong>compartida por el proveedor (CGNAT)</strong>: otros clientes de internet pueden tenerla. No sirve como prueba de presencia; usa "Solo QR" o pide una IP fija.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_agregar_red">
				<?php wp_nonce_field( 'pka_agregar_red' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pka-red">IP o rango</label></th>
						<td>
							<input type="text" id="pka-red" name="red" class="regular-text code" value="<?php echo esc_attr( $ip ); ?>" required>
							<p class="description">Agrégala estando conectado al WiFi del taller. En IPv6 usa el prefijo de la red, por ejemplo <code>2800:200:e840:1a00::/56</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-nombre-red">Nombre</label></th>
						<td><input type="text" id="pka-nombre-red" name="nombre" class="regular-text" value="WiFi del taller"></td>
					</tr>
				</table>
				<?php submit_button( 'Agregar red', 'secondary' ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr><th>Red</th><th>Nombre</th><th>Estado</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $redes ) : ?>
					<tr><td colspan="4">Todavía no hay redes. Sin ellas, la presencia solo se prueba con el QR.</td></tr>
				<?php endif; ?>
				<?php foreach ( $redes as $red ) : ?>
					<tr>
						<td><code><?php echo esc_html( $red['red'] ); ?></code></td>
						<td><?php echo esc_html( $red['nombre'] ); ?></td>
						<td><?php echo $red['activa'] ? 'Activa' : 'Desactivada'; ?></td>
						<td><a href="<?php echo esc_url( Menu::urlAccion( 'pka_cambiar_red', array( 'id' => $red['id'] ) ) ); ?>"><?php echo $red['activa'] ? 'Desactivar' : 'Activar'; ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php self::pantallas(); ?>
		</div>
		<?php
	}

	public static function guardarModo(): void {
		self::exigir( 'pka_guardar_modo' );

		$modo = ModoPresencia::tryFrom( isset( $_POST['modo'] ) ? sanitize_key( wp_unslash( $_POST['modo'] ) ) : '' );

		if ( null === $modo ) {
			Avisos::agregar( 'error', 'Modo desconocido.' );
			Menu::volver( self::SLUG );
		}

		$antes                    = Ajustes::todos();
		$ajustes                  = $antes;
		$ajustes['modo_presencia'] = $modo->value;
		update_option( Ajustes::OPCION, $ajustes );

		Auditoria::registrar( 'ajustes.modo_presencia', 'ajustes', null, array( 'modo_presencia' => $antes['modo_presencia'] ), array( 'modo_presencia' => $modo->value ) );
		Avisos::agregar( 'success', 'Modo guardado: ' . esc_html( $modo->etiqueta() ) . '.' );
		Menu::volver( self::SLUG );
	}

	public static function agregarRed(): void {
		global $wpdb;

		self::exigir( 'pka_agregar_red' );

		$texto  = isset( $_POST['red'] ) ? sanitize_text_field( wp_unslash( $_POST['red'] ) ) : '';
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';

		try {
			$red = RedAutorizada::desde( $texto );
		} catch ( InvalidArgumentException $e ) {
			Avisos::agregar( 'error', esc_html( $e->getMessage() ) );
			Menu::volver( self::SLUG );
		}

		$wpdb->insert(
			Tablas::nombre( 'redes' ),
			array(
				'red'        => $red->texto,
				'nombre'     => mb_substr( $nombre, 0, 80 ),
				'activa'     => 1,
				'creada_por' => get_current_user_id(),
				'creado_en'  => Peticion::ahoraUtc(),
			)
		);

		Auditoria::registrar( 'red.agregar', 'red', (int) $wpdb->insert_id, null, array( 'red' => $red->texto, 'nombre' => $nombre ) );
		Avisos::agregar( 'success', 'Red <code>' . esc_html( $red->texto ) . '</code> agregada.' . ( RedAutorizada::esCgnat( $texto ) ? ' Ojo: es una IP compartida (CGNAT).' : '' ) );
		Menu::volver( self::SLUG );
	}

	public static function cambiarRed(): void {
		global $wpdb;

		self::exigir( 'pka_cambiar_red' );

		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$tabla = Tablas::nombre( 'redes' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $fila ) {
			$nueva = $fila['activa'] ? 0 : 1;
			$wpdb->update( $tabla, array( 'activa' => $nueva ), array( 'id' => $id ) );
			Auditoria::registrar( 'red.' . ( $nueva ? 'activar' : 'desactivar' ), 'red', $id, array( 'activa' => (int) $fila['activa'] ), array( 'activa' => $nueva ) );
		}

		Menu::volver( self::SLUG );
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
