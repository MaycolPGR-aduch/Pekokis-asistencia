<?php

namespace Pekokis\Asistencia\WordPress;

use lbuchs\WebAuthn\WebAuthnException;
use Pekokis\Asistencia\Nucleo\Base64Url;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Inicio de sesión de gerencia (maqueta G0): la página de acceso de WordPress
 * con la marca, y "Entrar con Face ID o huella" con una passkey propia de cada
 * usuario del panel.
 *
 * La passkey se activa desde el perfil (después de entrar una vez con la
 * contraseña) y vive en la tabla llaves_gerencia. Es independiente de las
 * passkeys de los trabajadores (otra tabla y otro identificador de usuario),
 * así que una llave de trabajador nunca abre el panel ni al revés.
 */
final class AccesoGerencia {
	private const SEGUNDOS_RETO = 120;
	private const MAX_LLAVES    = 10;

	public static function registrar(): void {
		add_action( 'login_enqueue_scripts', array( self::class, 'estilosLogin' ) );
		add_filter( 'login_headerurl', static fn(): string => home_url( '/' ) );
		add_filter( 'login_headertext', static fn(): string => 'Pekokis Asistencia' );
		add_filter( 'login_message', array( self::class, 'mensajeLogin' ) );
		add_action( 'login_form', array( self::class, 'botonLlave' ) );
		add_filter( 'login_body_class', static fn( array $c ): array => array_merge( $c, self::esPantallaDeEntrar() ? array( 'pk-login', 'pk-login-entrar' ) : array( 'pk-login' ) ) );

		add_filter( 'login_redirect', array( self::class, 'alEntrar' ), 10, 3 );

		add_action( 'show_user_profile', array( self::class, 'cajaPerfil' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'scriptPerfil' ) );
		add_action( 'rest_api_init', array( self::class, 'rutas' ) );
	}

	// ---------- página de acceso (wp-login.php) ----------

	private static function esPantallaDeEntrar(): bool {
		$accion = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'login' === $accion;
	}

	public static function estilosLogin(): void {
		wp_enqueue_style( 'pk-tokens', PKA_URL . 'assets/tokens.css', array(), PKA_VERSION );
		wp_enqueue_style( 'pk-login', PKA_URL . 'assets/login.css', array( 'login', 'pk-tokens' ), PKA_VERSION );

		if ( self::esPantallaDeEntrar() ) {
			wp_enqueue_script( 'pk-login', PKA_URL . 'assets/login.js', array(), PKA_VERSION, true );
			wp_localize_script(
				'pk-login',
				'pkLogin',
				array(
					'api'       => esc_url_raw( rest_url( Api::ESPACIO . '/' ) ),
					'redirigir' => isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			);
		}
	}

	public static function mensajeLogin( string $mensaje ): string {
		if ( ! self::esPantallaDeEntrar() ) {
			return $mensaje;
		}

		return '<div class="pk-lg-titulo" id="pk-lg-titulo"><div class="pk-lg-cab"><img src="' . esc_url( PKA_URL . 'assets/logo-pekokis.png' ) . '" alt="Pekokis" width="105" height="28"><span class="pk-lg-etq">Gerencia</span></div>'
			. '<h2>Iniciar sesión</h2><p>Desde la laptop, el iPad o el iPhone de gerencia.</p></div>' . $mensaje;
	}

	/** El botón queda oculto: login.js lo muestra si el navegador sabe usar passkeys y lo pone debajo de "Acceder". */
	public static function botonLlave(): void {
		?>
		<div class="pk-lg-llave" id="pk-lg-llave" hidden>
			<div class="pk-lg-o"><span>o</span></div>
			<button type="button" class="button button-large pk-lg-boton" id="pk-lg-boton">
				<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 9V6a2 2 0 0 1 2-2h3M15 4h3a2 2 0 0 1 2 2v3M20 15v3a2 2 0 0 1-2 2h-3M9 20H6a2 2 0 0 1-2-2v-3M9 9.5v1M15 9.5v1M12 9.5V13h-1M9.5 16c1.5 1 3.5 1 5 0"/></svg>
				Entrar con Face ID o huella
			</button>
			<p class="pk-lg-nota">En el iPad o el iPhone de gerencia, después de entrar una vez con la contraseña y activarlo en tu perfil.</p>
			<p class="pk-lg-error" id="pk-lg-error" role="alert" hidden></p>
		</div>
		<?php
	}

	/** Después de entrar con la contraseña: en el celular, gerencia va a su versión para celular. */
	public static function alEntrar( string $destino, string $pedido, mixed $usuario ): string {
		if ( ! $usuario instanceof WP_User || ! self::puedeTenerLlave( $usuario ) ) {
			return $destino;
		}

		return self::destino( $usuario, $pedido );
	}

	// ---------- perfil: activar y quitar ----------

	/** Solo quien entra al panel de asistencia puede tener llave. */
	public static function puedeTenerLlave( WP_User $usuario ): bool {
		return user_can( $usuario, Capacidades::VER_EQUIPO ) || user_can( $usuario, Capacidades::AJUSTES ) || user_can( $usuario, 'manage_options' );
	}

	public static function cajaPerfil( WP_User $usuario ): void {
		if ( ! self::puedeTenerLlave( $usuario ) || get_current_user_id() !== $usuario->ID ) {
			return;
		}

		$llaves = self::llavesDe( $usuario->ID );
		?>
		<h2 id="pk-llaves">Entrar con Face ID o huella</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Este equipo</th>
				<td>
					<button type="button" class="button" id="pk-llave-activar">Activar Face ID o huella en este equipo</button>
					<p class="description">Después entras al panel de asistencia con un toque, sin escribir la contraseña. Actívalo en cada equipo que uses (iPhone, iPad, laptop con huella). Tu contraseña sigue sirviendo.</p>
					<p id="pk-llave-estado" role="status"></p>
				</td>
			</tr>
			<tr>
				<th scope="row">Equipos activados</th>
				<td>
					<?php if ( ! $llaves ) : ?>
						<p>Ninguno todavía.</p>
					<?php else : ?>
						<ul class="pk-llaves-lista">
							<?php foreach ( $llaves as $l ) : ?>
								<li>
									<strong><?php echo esc_html( '' !== $l['nombre'] ? $l['nombre'] : 'Equipo' ); ?></strong>
									— activado el <?php echo esc_html( Ajustes::local( $l['creado_en'], 'd/m/Y' ) ); ?>
									<?php echo $l['ultimo_uso'] ? '· último uso ' . esc_html( Ajustes::local( $l['ultimo_uso'], 'd/m/Y H:i' ) ) : ''; ?>
									<button type="button" class="button-link pk-llave-quitar" data-id="<?php echo (int) $l['id']; ?>">Quitar</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function scriptPerfil( string $pagina ): void {
		if ( 'profile.php' !== $pagina || ! self::puedeTenerLlave( wp_get_current_user() ) ) {
			return;
		}

		wp_enqueue_script( 'pk-llaves', PKA_URL . 'assets/llaves.js', array(), PKA_VERSION, true );
		wp_localize_script(
			'pk-llaves',
			'pkLlaves',
			array(
				'api'   => esc_url_raw( rest_url( Api::ESPACIO . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	// ---------- API ----------

	public static function rutas(): void {
		$conSesion = static fn(): bool => is_user_logged_in() && self::puedeTenerLlave( wp_get_current_user() );

		register_rest_route( Api::ESPACIO, '/gerencia/entrar/opciones', array( 'methods' => 'POST', 'callback' => array( self::class, 'opcionesEntrar' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( Api::ESPACIO, '/gerencia/entrar', array( 'methods' => 'POST', 'callback' => array( self::class, 'entrar' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( Api::ESPACIO, '/gerencia/llave/opciones', array( 'methods' => 'POST', 'callback' => array( self::class, 'opcionesActivar' ), 'permission_callback' => $conSesion ) );
		register_rest_route( Api::ESPACIO, '/gerencia/llave', array( 'methods' => 'POST', 'callback' => array( self::class, 'activar' ), 'permission_callback' => $conSesion ) );
		register_rest_route( Api::ESPACIO, '/gerencia/llave/quitar', array( 'methods' => 'POST', 'callback' => array( self::class, 'quitar' ), 'permission_callback' => $conSesion ) );
	}

	private static function bloqueado(): ?WP_Error {
		if ( Temporales::bloqueado( 'login|' . Peticion::ip(), Endurecimiento::MAX_FALLOS_LOGIN ) ) {
			return new WP_Error( 'pka_bloqueado', 'Demasiados intentos fallidos. Espera 15 minutos.', array( 'status' => 429 ) );
		}

		return null;
	}

	public static function opcionesEntrar(): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			return $bloqueo;
		}

		$servidor = Passkeys::servidor();
		$args     = $servidor->getGetArgs( array(), 60, true, true, true, true, true, true );
		$reto     = Temporales::guardar( 'reto', array( 'uso' => 'gerencia-entrar', 'desafio' => Base64Url::codificar( $servidor->getChallenge()->getBinaryString() ) ), self::SEGUNDOS_RETO );

		return self::respuesta( array( 'reto' => $reto, 'publicKey' => $args->publicKey ) );
	}

	public static function entrar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		global $wpdb;

		if ( $bloqueo = self::bloqueado() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			return $bloqueo;
		}

		$datos      = Temporales::tomar( 'reto', (string) $peticion->get_param( 'reto' ) );
		$credencial = (array) $peticion->get_param( 'credencial' );
		$error      = new WP_Error( 'pka_llave', 'No reconocimos esa llave. Entra con tu contraseña y actívala en tu perfil.', array( 'status' => 401 ) );

		if ( null === $datos || 'gerencia-entrar' !== $datos['uso'] ) {
			return new WP_Error( 'pka_reto', 'La solicitud venció. Vuelve a intentarlo.', array( 'status' => 400 ) );
		}

		$idBinario = Base64Url::decodificar( (string) ( $credencial['id'] ?? '' ) );
		$cliente   = Base64Url::decodificar( (string) ( $credencial['clientDataJSON'] ?? '' ) );
		$autentic  = Base64Url::decodificar( (string) ( $credencial['authenticatorData'] ?? '' ) );
		$firma     = Base64Url::decodificar( (string) ( $credencial['signature'] ?? '' ) );

		if ( null === $idBinario || null === $cliente || null === $autentic || null === $firma || ! Passkeys::origenValido( $cliente ) ) {
			Endurecimiento::sumarFalloLogin();
			return $error;
		}

		$tabla = Tablas::nombre( 'llaves_gerencia' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE credential_hash = %s AND revocada_en IS NULL", hash( 'sha256', $idBinario ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user  = $fila ? get_userdata( (int) $fila['user_id'] ) : false;

		if ( ! $fila || ! $user || ! self::puedeTenerLlave( $user ) ) {
			Endurecimiento::sumarFalloLogin();
			return $error;
		}

		$servidor = Passkeys::servidor();

		try {
			$servidor->processGet( $cliente, $autentic, $firma, $fila['public_key'], (string) Base64Url::decodificar( $datos['desafio'] ), (int) $fila['sign_count'], true, true );
		} catch ( WebAuthnException $e ) {
			Endurecimiento::sumarFalloLogin();
			return $error;
		}

		$wpdb->update(
			$tabla,
			array(
				'sign_count' => (int) ( $servidor->getSignatureCounter() ?? $fila['sign_count'] ),
				'ultimo_uso' => Peticion::ahoraUtc(),
			),
			array( 'id' => (int) $fila['id'] )
		);

		Temporales::limpiarFallos( 'login|' . Peticion::ip() );
		wp_set_current_user( $user->ID );
		if ( ! headers_sent() ) {
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$pedido = (string) $peticion->get_param( 'redirigir' );

		return self::respuesta( array( 'ir' => self::destino( $user, $pedido ) ) );
	}

	/**
	 * A dónde va gerencia al entrar: lo pedido (si es de este sitio) o el panel.
	 * En el celular, la versión para celular (Gerencia en el celular, M1).
	 */
	public static function destino( WP_User $usuario, string $pedido = '' ): string {
		$panel  = admin_url();
		$pedido = '' !== $pedido ? wp_validate_redirect( $pedido, $panel ) : $panel;

		if ( ( $pedido === $panel || admin_url( '/' ) === $pedido ) && wp_is_mobile() && user_can( $usuario, Capacidades::VER_EQUIPO ) ) {
			return GerenciaMovil::url();
		}

		return $pedido;
	}

	public static function opcionesActivar(): WP_REST_Response|WP_Error {
		$usuario = wp_get_current_user();

		if ( count( self::llavesDe( $usuario->ID ) ) >= self::MAX_LLAVES ) {
			return new WP_Error( 'pka_llaves', 'Ya tienes ' . self::MAX_LLAVES . ' equipos activados. Quita alguno que no uses.', array( 'status' => 400 ) );
		}

		$servidor = Passkeys::servidor();
		$excluir  = array_map( static fn( array $l ): string => (string) Base64Url::decodificar( $l['credential_id'] ), self::llavesDe( $usuario->ID ) );
		$args     = $servidor->getCreateArgs( self::userHandle( $usuario->ID ), $usuario->user_login, 'Gerencia · ' . $usuario->display_name, 60, true, true, null, $excluir );

		$args->publicKey->attestation = 'none';
		unset( $args->publicKey->extensions );

		$reto = Temporales::guardar(
			'reto',
			array(
				'uso'     => 'gerencia-activar',
				'desafio' => Base64Url::codificar( $servidor->getChallenge()->getBinaryString() ),
				'user_id' => $usuario->ID,
			),
			self::SEGUNDOS_RETO
		);

		return self::respuesta( array( 'reto' => $reto, 'publicKey' => $args->publicKey ) );
	}

	public static function activar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		global $wpdb;

		$usuario    = wp_get_current_user();
		$datos      = Temporales::tomar( 'reto', (string) $peticion->get_param( 'reto' ) );
		$credencial = (array) $peticion->get_param( 'credencial' );

		if ( null === $datos || 'gerencia-activar' !== $datos['uso'] || $usuario->ID !== (int) $datos['user_id'] ) {
			return new WP_Error( 'pka_reto', 'La solicitud venció. Vuelve a intentarlo.', array( 'status' => 400 ) );
		}

		$cliente     = Base64Url::decodificar( (string) ( $credencial['clientDataJSON'] ?? '' ) );
		$attestation = Base64Url::decodificar( (string) ( $credencial['attestationObject'] ?? '' ) );

		if ( null === $cliente || null === $attestation || ! Passkeys::origenValido( $cliente ) ) {
			return new WP_Error( 'pka_llave', 'No se pudo verificar la llave.', array( 'status' => 400 ) );
		}

		try {
			$r = Passkeys::servidor()->processCreate( $cliente, $attestation, (string) Base64Url::decodificar( $datos['desafio'] ), true, true, false );
		} catch ( WebAuthnException $e ) {
			return new WP_Error( 'pka_llave', 'No se pudo verificar la llave: ' . $e->getMessage(), array( 'status' => 400 ) );
		}

		$nombre = self::equipo();
		$ok     = $wpdb->insert(
			Tablas::nombre( 'llaves_gerencia' ),
			array(
				'user_id'         => $usuario->ID,
				'credential_id'   => Base64Url::codificar( $r->credentialId ),
				'credential_hash' => hash( 'sha256', $r->credentialId ),
				'public_key'      => $r->credentialPublicKey,
				'sign_count'      => (int) $r->signatureCounter,
				'nombre'          => $nombre,
				'creado_en'       => Peticion::ahoraUtc(),
			)
		);

		if ( false === $ok ) {
			return new WP_Error( 'pka_llave', 'Este equipo ya estaba activado.', array( 'status' => 409 ) );
		}

		Auditoria::registrar( 'gerencia.llave_activada', 'usuario', $usuario->ID, null, array( 'equipo' => $nombre ) );

		return self::respuesta( array( 'ok' => true, 'nombre' => $nombre ) );
	}

	public static function quitar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		global $wpdb;

		$usuario = wp_get_current_user();
		$id      = absint( $peticion->get_param( 'id' ) );
		$llave   = array_values( array_filter( self::llavesDe( $usuario->ID ), static fn( array $l ): bool => (int) $l['id'] === $id ) );

		if ( ! $llave ) {
			return new WP_Error( 'pka_llave', 'Ese equipo no está activado.', array( 'status' => 404 ) );
		}

		$wpdb->update( Tablas::nombre( 'llaves_gerencia' ), array( 'revocada_en' => Peticion::ahoraUtc() ), array( 'id' => $id ) );
		Auditoria::registrar( 'gerencia.llave_quitada', 'usuario', $usuario->ID, array( 'equipo' => $llave[0]['nombre'] ), null );

		return self::respuesta( array( 'ok' => true ) );
	}

	/** @return array<int, array<string, mixed>> */
	public static function llavesDe( int $userId ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'llaves_gerencia' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT id, credential_id, nombre, creado_en, ultimo_uso FROM {$tabla} WHERE user_id = %d AND revocada_en IS NULL ORDER BY id", $userId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Nombre corto del equipo para la lista del perfil ("iPhone · Safari"). */
	private static function equipo(): string {
		$ua = Peticion::navegador();

		$sistema = match ( true ) {
			str_contains( $ua, 'iPhone' )    => 'iPhone',
			str_contains( $ua, 'iPad' )      => 'iPad',
			str_contains( $ua, 'Android' )   => 'Android',
			str_contains( $ua, 'Macintosh' ) => 'Mac o iPad',
			str_contains( $ua, 'Windows' )   => 'Windows',
			default                          => 'Equipo',
		};
		$navegador = match ( true ) {
			str_contains( $ua, 'Edg/' )                                 => 'Edge',
			str_contains( $ua, 'Chrome/' ) || str_contains( $ua, 'CriOS' ) => 'Chrome',
			str_contains( $ua, 'Firefox/' ) || str_contains( $ua, 'FxiOS' ) => 'Firefox',
			str_contains( $ua, 'Safari/' )                              => 'Safari',
			default                                                     => '',
		};

		return '' !== $navegador ? $sistema . ' · ' . $navegador : $sistema;
	}

	/** Identificador opaco del usuario dentro de la passkey, distinto del de los trabajadores. */
	private static function userHandle( int $userId ): string {
		return hash_hmac( 'sha256', 'gerencia|' . $userId, Secretos::clave(), true );
	}

	/** @param array<string, mixed> $datos */
	private static function respuesta( array $datos ): WP_REST_Response {
		$r = new WP_REST_Response( $datos );
		$r->header( 'Cache-Control', 'no-store' );

		return $r;
	}
}
