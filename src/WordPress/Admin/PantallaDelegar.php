<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Tablas;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Delegar permisos (maquetas G17).
 *
 * El supervisor es gerencia (RR.HH. o administrador). Un trabajador recibe solo
 * los permisos que se le encienden aquí, como capacidades de su propio usuario
 * de WordPress (se le crea uno si no tiene). Sueldos y recibos se delegan solo a
 * propósito. Todo queda en la auditoría.
 */
final class PantallaDelegar {
	public const SLUG = 'pka-delegar';

	/** Marca en el usuario: ['desde' => 'Y-m-d H:i:s', 'permisos' => [...]]. */
	public const META = 'pka_delegado';

	/** clave => [texto, ayuda, capacidades, sensible]. */
	public const PERMISOS = array(
		'horarios'   => array( 'Ingresar y modificar horarios', 'Horarios y feriados.', array( Capacidades::HORARIOS ), false ),
		'asistencia' => array( 'Revisar la asistencia de todos', 'Hoy, Dashboard, registros e informes de todo el taller.', array( Capacidades::VER_EQUIPO, Capacidades::VER_TODO ), false ),
		'corregir'   => array( 'Corregir registros y aprobar solicitudes', 'Marcas manuales, anular, aprobar "olvidé marcar" y marcas por revisar. Nunca las suyas.', array( Capacidades::VER_EQUIPO, Capacidades::APROBAR, Capacidades::MARCA_MANUAL ), false ),
		'sueldos'    => array( 'Ver sueldos y la calculadora de pagos', 'Honorarios, tarifas y montos.', array( Capacidades::VER_SUELDOS ), true ),
		'recibos'    => array( 'Recibir recibos por honorarios', 'Revisar los recibos, verlos en PDF y marcarlos recibidos u observados.', array( Capacidades::RECIBOS ), true ),
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_delegar', array( self::class, 'guardar' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$elegido   = isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$delegados = self::delegados();
		$t         = $elegido ? Trabajadores::buscar( $elegido ) : null;
		$usuario   = $t && $t['user_id'] ? get_userdata( (int) $t['user_id'] ) : null;
		$actuales  = $usuario ? self::permisosDe( $usuario ) : array();
		$libres    = array_filter( Trabajadores::listar(), static fn( array $x ): bool => ! isset( $delegados[ (int) $x['id'] ] ) );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Delegar permisos</h1>
			<p class="description">El supervisor es gerencia. Si lo necesitas, puedes darle a un trabajador solo los permisos que elijas, y quitárselos cuando quieras. Lo que haga queda en la auditoría con su nombre.</p>

			<div class="pka-dos">
				<div class="pka-caja">
					<h2>Con permisos delegados</h2>
					<?php if ( ! $delegados ) : ?>
						<p class="description">Nadie tiene permisos delegados.</p>
					<?php else : ?>
						<ul class="pka-alertas">
							<?php foreach ( $delegados as $tid => $d ) : ?>
								<li>
									<span class="pka-av"><?php echo esc_html( self::iniciales( $d['trabajador'] ) ); ?></span>
									<div>
										<b><?php echo esc_html( Trabajadores::nombreCompleto( $d['trabajador'] ) ); ?></b>
										<span>Desde el <?php echo esc_html( Ajustes::local( $d['desde'], 'j \d\e F' ) ); ?> · <?php echo count( $d['permisos'] ); ?> <?php echo 1 === count( $d['permisos'] ) ? 'permiso' : 'permisos'; ?></span>
									</div>
									<span class="pka-chip ok">Activo</span>
									<a class="button" href="<?php echo esc_url( self::url( $tid ) ); ?>">Editar</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<label class="screen-reader-text" for="pka-delegar-a">Trabajador</label>
						<select id="pka-delegar-a" name="trabajador" required style="flex:1;min-width:180px">
							<option value="">Elige a un trabajador…</option>
							<?php foreach ( $libres as $x ) : ?>
								<option value="<?php echo (int) $x['id']; ?>" <?php selected( $elegido, (int) $x['id'] ); ?>><?php echo esc_html( $x['apellidos'] . ', ' . $x['nombres'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<button class="button button-primary">+ Delegar a un trabajador</button>
					</form>
				</div>

				<?php if ( $t ) : ?>
					<div class="pka-caja">
						<h2>Permisos de <?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></h2>
						<?php if ( $usuario && user_can( $usuario, 'manage_options' ) ) : ?>
							<p>Es administrador de WordPress: ya tiene todos los permisos.</p>
						<?php elseif ( $usuario && $usuario->ID === get_current_user_id() ) : ?>
							<p>No puedes cambiar tus propios permisos.</p>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="pka_delegar">
								<input type="hidden" name="trabajador" value="<?php echo (int) $t['id']; ?>">
								<?php wp_nonce_field( 'pka_delegar' ); ?>
								<ul class="pka-permisos">
									<?php foreach ( self::PERMISOS as $clave => [ $texto, $ayuda, , $sensible ] ) : ?>
										<li>
											<label for="pka-perm-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $texto ); ?><?php echo $sensible ? ' <span class="pka-chip solo">Solo a propósito</span>' : ''; ?><small><?php echo esc_html( $ayuda ); ?></small></label>
											<span class="pka-switch"><input type="checkbox" role="switch" id="pka-perm-<?php echo esc_attr( $clave ); ?>" name="permisos[]" value="<?php echo esc_attr( $clave ); ?>" <?php checked( in_array( $clave, $actuales, true ) ); ?>><span></span></span>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="pka-nota-rosa"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Los sueldos, los pagos y los recibos se delegan solo si los activas a propósito. Nadie aprueba ni corrige sus propias marcas.</p>
								<?php if ( ! $usuario ) : ?>
									<p>
										<label for="pka-deleg-correo"><strong>Correo para su acceso al panel</strong></label><br>
										<input type="email" id="pka-deleg-correo" name="correo" class="regular-text" value="<?php echo esc_attr( (string) $t['correo'] ); ?>">
										<br><span class="description">Todavía no entra al panel: se le creará un acceso y le llegará un correo para elegir su contraseña.</span>
									</p>
								<?php endif; ?>
								<p style="display:flex;gap:8px">
									<button class="button button-primary" name="que" value="guardar">Guardar permisos</button>
									<?php if ( $actuales ) : ?>
										<button class="button" name="que" value="quitar" onclick="return confirm('¿Quitarle todos los permisos delegados?')">Quitar todos</button>
									<?php endif; ?>
								</p>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function guardar(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_delegar' );

		$tid      = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$quitar   = isset( $_POST['que'] ) && 'quitar' === $_POST['que'];
		$permisos = $quitar ? array() : array_values( array_intersect( array_keys( self::PERMISOS ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['permisos'] ?? array() ) ) ) );
		$correo   = isset( $_POST['correo'] ) ? sanitize_email( wp_unslash( $_POST['correo'] ) ) : '';
		$r        = self::delegar( $tid, $permisos, $correo );

		if ( is_wp_error( $r ) ) {
			Avisos::agregar( 'error', esc_html( $r->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', $permisos ? 'Permisos guardados.' : 'Se quitaron sus permisos delegados.' );
		}

		Menu::volverA( self::url( $tid ) );
	}

	/**
	 * Deja al trabajador exactamente con estos permisos delegados.
	 *
	 * @param string[] $permisos Claves de PERMISOS.
	 */
	public static function delegar( int $trabajadorId, array $permisos, string $correo = '' ): true|WP_Error {
		global $wpdb;

		$t = Trabajadores::buscar( $trabajadorId );

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe o no está activo.' );
		}

		$usuario = $t['user_id'] ? get_userdata( (int) $t['user_id'] ) : null;

		if ( $usuario && ( user_can( $usuario, 'manage_options' ) || $usuario->ID === get_current_user_id() ) ) {
			return new WP_Error( 'pka_propio', 'A esa persona no se le pueden cambiar los permisos desde aquí.' );
		}

		if ( ! $usuario && ! $permisos ) {
			return true;
		}

		if ( ! $usuario ) {
			$usuario = self::crearAcceso( $t, $correo );

			if ( $usuario instanceof WP_Error ) {
				return $usuario;
			}

			$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'user_id' => $usuario->ID ), array( 'id' => $trabajadorId ) );
		}

		$antes = self::permisosDe( $usuario );
		$caps  = array();

		foreach ( $permisos as $p ) {
			$caps = array_merge( $caps, self::PERMISOS[ $p ][2] );
		}

		foreach ( self::todasLasCapacidades() as $cap ) {
			if ( in_array( $cap, $caps, true ) ) {
				$usuario->add_cap( $cap );
			} else {
				$usuario->remove_cap( $cap );
			}
		}

		if ( $permisos ) {
			$meta = get_user_meta( $usuario->ID, self::META, true );
			update_user_meta( $usuario->ID, self::META, array( 'desde' => is_array( $meta ) && ! empty( $meta['desde'] ) ? $meta['desde'] : gmdate( 'Y-m-d H:i:s' ), 'permisos' => $permisos ) );
		} else {
			delete_user_meta( $usuario->ID, self::META );
		}

		if ( $antes !== $permisos ) {
			Auditoria::registrar( 'permiso.delegar', 'trabajador', $trabajadorId, array( 'permisos' => $antes ), array( 'permisos' => $permisos ) );
		}

		return true;
	}

	/** @return string[] Permisos delegados del usuario. */
	public static function permisosDe( WP_User $usuario ): array {
		$meta = get_user_meta( $usuario->ID, self::META, true );

		return is_array( $meta ) && isset( $meta['permisos'] ) ? array_values( (array) $meta['permisos'] ) : array();
	}

	/** ¿El usuario actúa como supervisor delegado? (para marcarlo en la auditoría). */
	public static function esDelegado( int $userId ): bool {
		$meta = get_user_meta( $userId, self::META, true );

		return is_array( $meta ) && ! empty( $meta['permisos'] );
	}

	/** @return array<int, array{trabajador: array<string, mixed>, desde: string, permisos: string[]}> */
	public static function delegados(): array {
		$lista = array();

		foreach ( Trabajadores::listar() as $t ) {
			$meta = $t['user_id'] ? get_user_meta( (int) $t['user_id'], self::META, true ) : null;

			if ( is_array( $meta ) && ! empty( $meta['permisos'] ) ) {
				$lista[ (int) $t['id'] ] = array( 'trabajador' => $t, 'desde' => (string) $meta['desde'], 'permisos' => (array) $meta['permisos'] );
			}
		}

		return $lista;
	}

	public static function url( int $trabajadorId = 0 ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( $trabajadorId ? '&trabajador=' . $trabajadorId : '' ) );
	}

	/** Usuario de WordPress solo para el panel (rol sin permisos propios); le llega un correo para elegir su contraseña. */
	private static function crearAcceso( array $t, string $correo ): WP_User|WP_Error {
		if ( ! is_email( $correo ) ) {
			return new WP_Error( 'pka_correo', 'Escribe un correo válido: ahí le llegará el enlace para elegir su contraseña.' );
		}

		if ( email_exists( $correo ) ) {
			return new WP_Error( 'pka_correo', 'Ese correo ya tiene un usuario. Enlázalo desde la ficha del trabajador ("Usuario de wp-admin").' );
		}

		$base  = sanitize_user( remove_accents( strtolower( $t['nombres'] . '.' . strtok( (string) $t['apellidos'], ' ' ) ) ), true );
		$login = $base;

		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}

		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $correo,
				'user_pass'    => wp_generate_password( 32 ),
				'display_name' => Trabajadores::nombreCompleto( $t ),
				'first_name'   => (string) $t['nombres'],
				'last_name'    => (string) $t['apellidos'],
				'role'         => Capacidades::ROL_DELEGADO,
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_new_user_notification( $id, null, 'user' );
		Auditoria::registrar( 'permiso.crear_acceso', 'trabajador', (int) $t['id'], null, array( 'usuario' => $login ) );

		return get_userdata( $id );
	}

	/** @return string[] */
	private static function todasLasCapacidades(): array {
		$todas = array();

		foreach ( self::PERMISOS as [ , , $caps ] ) {
			$todas = array_merge( $todas, $caps );
		}

		return array_values( array_unique( $todas ) );
	}

	private static function iniciales( array $t ): string {
		return mb_strtoupper( mb_substr( trim( (string) $t['nombres'] ), 0, 1 ) . mb_substr( trim( (string) $t['apellidos'] ), 0, 1 ) );
	}
}
