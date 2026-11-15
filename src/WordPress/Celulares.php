<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\ValidacionIdentidad;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registro propio y cambio de celular desde el taller, y liberar un celular
 * (maquetas T0D, T1 y G13).
 *
 * - Persona nueva: deja sus datos, crea su llave y queda "pendiente" hasta que
 *   gerencia la aprueba. Hasta entonces no puede entrar ni marcar.
 * - Cambio de celular: busca su perfil por nombre, confirma con DNI y cumpleaños
 *   (5 fallos bloquean 15 min) y crea la llave del celular nuevo, que queda
 *   pendiente. Mientras tanto sigue marcando con el anterior; al aprobar, el
 *   anterior queda dado de baja.
 * - Liberar: la persona lo pide desde su celular; gerencia lo aprueba (o libera
 *   un celular directamente). Los datos y las horas no se tocan.
 *
 * Registrar o cambiar el celular exige estar en el taller (WiFi o QR), igual que marcar.
 * El alta por RR.HH. con enlace de activación sigue funcionando.
 */
final class Celulares {
	public const REGISTRO = 'registro';
	public const CAMBIO   = 'cambio';
	public const LIBERAR  = 'liberar';

	public const PENDIENTE = 'pendiente';
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';
	public const CANCELADA = 'cancelada';

	public const TIPOS = array(
		self::REGISTRO => 'Persona nueva',
		self::CAMBIO   => 'Cambio de celular',
		self::LIBERAR  => 'Liberar celular',
	);

	private const MAX_FALLOS     = 5;
	private const VENTANA        = 15 * MINUTE_IN_SECONDS;
	private const SEGUNDOS_FLUJO = 15 * MINUTE_IN_SECONDS;

	public static function enElTaller(): bool {
		return null !== Ajustes::modoPresencia()->evaluar( Presencia::contexto() );
	}

	private static function fueraDelTaller(): WP_Error {
		return new WP_Error( 'pka_sin_presencia', 'Para registrar tu celular tienes que estar en el taller: conéctate al WiFi o escanea el QR de la pantalla.', array( 'status' => 403 ) );
	}

	/**
	 * Perfiles activos que coinciden con lo escrito. Solo nombres: nunca DNI ni teléfono.
	 *
	 * @return array<int, array{id: int, nombre: string}>
	 */
	public static function buscarPerfiles( string $texto ): array {
		global $wpdb;

		$texto = trim( preg_replace( '/\s+/u', ' ', $texto ) );

		if ( mb_strlen( $texto ) < 3 ) {
			return array();
		}

		$like  = '%' . $wpdb->esc_like( $texto ) . '%';
		$t     = Tablas::nombre( 'trabajadores' );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT id, nombres, apellidos FROM {$t} WHERE estado = %s AND ( nombres LIKE %s OR apellidos LIKE %s OR CONCAT(nombres, ' ', apellidos) LIKE %s ) ORDER BY nombres, apellidos LIMIT 8", Trabajadores::ACTIVO, $like, $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn( array $f ): array => array( 'id' => (int) $f['id'], 'nombre' => trim( $f['nombres'] . ' ' . $f['apellidos'] ) ),
			$filas
		);
	}

	/**
	 * Persona nueva (T1B): valida los datos y crea su perfil "pendiente".
	 *
	 * @param array<string, mixed> $entrada nombres, apellidos, telefono, dni, cumple_dia, cumple_mes, acepta_aviso.
	 * @return array<string, mixed>|WP_Error El perfil, o error con data['errores'] por campo.
	 */
	public static function iniciarRegistro( array $entrada ): array|WP_Error {
		global $wpdb;

		if ( ! self::enElTaller() ) {
			return self::fueraDelTaller();
		}

		$horario = (int) array_key_first( Trabajadores::horarios() );
		$datos   = ValidacionTrabajador::normalizar( array_intersect_key( $entrada, array_flip( array( 'nombres', 'apellidos', 'telefono', 'dni' ) ) ) + array( 'horario_id' => $horario ) );
		$errores = ValidacionTrabajador::errores( $datos );
		$cumple  = ValidacionIdentidad::cumpleanos( $entrada['cumple_dia'] ?? '', $entrada['cumple_mes'] ?? '' );

		if ( '' === $datos['telefono'] ) {
			$errores['telefono'] = 'Escribe tu celular o WhatsApp.';
		}

		if ( '' === $cumple ) {
			$errores['cumpleanos'] = '' !== (string) ( $entrada['cumple_dia'] ?? '' ) && '' !== (string) ( $entrada['cumple_mes'] ?? '' ) ? 'Esa fecha no existe: revisa el día.' : 'Elige el día y el mes de tu cumpleaños.';
		}

		$tabla = Tablas::nombre( 'trabajadores' );
		$otro  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE dni = %s", $datos['dni'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Un registro propio que quedó a medias o fue rechazado se puede rehacer.
		$reusar = $otro && 'registro' === $otro['origen'] && in_array( $otro['estado'], array( Trabajadores::PENDIENTE, Trabajadores::RECHAZADO ), true ) && ! self::pendienteDe( (int) $otro['id'] );

		if ( $otro && ! $reusar ) {
			$errores['dni'] = Trabajadores::PENDIENTE === $otro['estado'] ? 'Ese DNI ya tiene un registro esperando la aprobación de gerencia.' : 'Ya hay un perfil con ese DNI. Si cambiaste de celular, vuelve y toca "Cambié de celular".';
		}

		if ( (int) ( $entrada['acepta_aviso'] ?? 0 ) !== Aviso::version() ) {
			$errores['aviso'] = 'Lee el aviso de datos personales y marca la casilla.';
		}

		if ( $errores ) {
			return new WP_Error( 'pka_datos_invalidos', 'Revisa tus datos.', array( 'status' => 422, 'errores' => $errores ) );
		}

		$ahora = Peticion::ahoraUtc();
		$fila  = array(
			'dni'               => $datos['dni'],
			'nombres'           => $datos['nombres'],
			'apellidos'         => $datos['apellidos'],
			'telefono'          => $datos['telefono'],
			'horario_id'        => $horario,
			'cumpleanos'        => $cumple,
			'estado'            => Trabajadores::PENDIENTE,
			'origen'            => 'registro',
			'aviso_version'     => Aviso::version(),
			'aviso_aceptado_en' => $ahora,
			'actualizado_en'    => $ahora,
		);

		if ( $reusar ) {
			$id = (int) $otro['id'];
			$wpdb->update( $tabla, $fila, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $tabla, $fila + array( 'creado_en' => $ahora ) );
			$id = (int) $wpdb->insert_id;
		}

		Auditoria::registrar( 'trabajador.registrarse', 'trabajador', $id, null, array_diff_key( $fila, array( 'actualizado_en' => 1, 'aviso_aceptado_en' => 1 ) ), 'Registro propio desde el taller', $id );

		return Trabajadores::buscar( $id );
	}

	/**
	 * Cambio de celular (T1H): DNI completo y cumpleaños. 5 fallos bloquean 15 minutos.
	 *
	 * @return array<string, mixed>|WP_Error El perfil confirmado.
	 */
	public static function confirmarIdentidad( int $trabajadorId, string $dni, mixed $dia, mixed $mes ): array|WP_Error {
		if ( ! self::enElTaller() ) {
			return self::fueraDelTaller();
		}

		$porIp      = 'identidad_ip|' . Peticion::ip();
		$porPersona = 'identidad|' . $trabajadorId;
		$bloqueo    = new WP_Error( 'pka_bloqueado', 'Hubo 5 intentos equivocados. Espera 15 minutos o pide ayuda a gerencia.', array( 'status' => 429, 'intentos' => 0 ) );

		if ( Temporales::bloqueado( $porIp, self::MAX_FALLOS ) || Temporales::bloqueado( $porPersona, self::MAX_FALLOS ) ) {
			return $bloqueo;
		}

		$t = Trabajadores::buscar( $trabajadorId );

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'No encontramos ese perfil. Pide ayuda a gerencia.', array( 'status' => 404 ) );
		}

		if ( '' === (string) $t['cumpleanos'] ) {
			return new WP_Error( 'pka_sin_cumple', 'Tu perfil todavía no tiene tu cumpleaños, así que no podemos confirmar que eres tú. Pide a RR.HH. un enlace para activar tu celular.', array( 'status' => 409 ) );
		}

		if ( ! ValidacionIdentidad::esLaPersona( (string) $t['dni'], (string) $t['cumpleanos'], $dni, ValidacionIdentidad::cumpleanos( $dia, $mes ) ) ) {
			Temporales::sumarFallo( $porIp, self::VENTANA );
			Temporales::sumarFallo( $porPersona, self::VENTANA );
			$quedan = max( 0, self::MAX_FALLOS - max( Temporales::intentos( $porIp ), Temporales::intentos( $porPersona ) ) );

			if ( 0 === $quedan ) {
				Auditoria::registrar( 'celular.identidad_bloqueada', 'trabajador', $trabajadorId, null, null, '5 intentos equivocados de DNI y cumpleaños', $trabajadorId );

				return $bloqueo;
			}

			return new WP_Error( 'pka_identidad', 'El DNI o el cumpleaños no coinciden. Te quedan ' . $quedan . ( 1 === $quedan ? ' intento.' : ' intentos.' ), array( 'status' => 401, 'intentos' => $quedan ) );
		}

		Temporales::limpiarFallos( $porPersona );

		return $t;
	}

	/** Permiso de 15 minutos para crear la llave del flujo confirmado. */
	public static function abrirFlujo( string $tipo, int $trabajadorId ): string {
		return Temporales::guardar( 'flujo_celular', array( 'tipo' => $tipo, 'trabajador_id' => $trabajadorId ), self::SEGUNDOS_FLUJO );
	}

	/** @return array{tipo: string, trabajador_id: int}|null */
	public static function leerFlujo( string $token ): ?array {
		$f = Temporales::leer( 'flujo_celular', $token );

		return $f && isset( self::TIPOS[ $f['tipo'] ] ) ? array( 'tipo' => (string) $f['tipo'], 'trabajador_id' => (int) $f['trabajador_id'] ) : null;
	}

	/**
	 * Guarda la llave del celular (T1D o T1I) y deja la solicitud para gerencia.
	 *
	 * @param array<string, mixed> $credencial
	 * @return array{tipo: string, nombre: string}|WP_Error
	 */
	public static function completar( string $flujo, string $reto, array $credencial ): array|WP_Error {
		global $wpdb;

		$f = self::leerFlujo( $flujo );

		if ( null === $f || self::LIBERAR === $f['tipo'] ) {
			return new WP_Error( 'pka_flujo', 'Pasó mucho tiempo. Vuelve a empezar.', array( 'status' => 400 ) );
		}

		if ( ! self::enElTaller() ) {
			return self::fueraDelTaller();
		}

		$tid    = $f['trabajador_id'];
		$t      = Trabajadores::buscar( $tid );
		$espera = self::REGISTRO === $f['tipo'] ? Trabajadores::PENDIENTE : Trabajadores::ACTIVO;

		if ( ! $t || $espera !== $t['estado'] ) {
			return new WP_Error( 'pka_flujo', 'Ese perfil cambió mientras te registrabas. Vuelve a empezar.', array( 'status' => 409 ) );
		}

		if ( Dispositivos::perteneceAOtro( $tid ) ) {
			return new WP_Error( 'pka_celular_ocupado', 'Este celular ya está registrado para otra persona. Cada trabajador usa su propio celular.', array( 'status' => 409 ) );
		}

		$dispositivo = Dispositivos::vincular( $tid );
		$llave       = Passkeys::completarRegistro( $reto, $credencial, $tid, $dispositivo, self::CAMBIO === $f['tipo'] );

		if ( $llave instanceof WP_Error ) {
			return $llave;
		}

		Temporales::borrar( 'flujo_celular', $flujo );

		// Una solicitud nueva reemplaza la anterior del mismo tipo que seguía pendiente.
		foreach ( self::solicitudes( $tid, $f['tipo'], self::PENDIENTE ) as $vieja ) {
			self::cerrar( (int) $vieja['id'], self::CANCELADA, 'Reemplazada por una nueva' );
			self::revocarLlaveDe( $vieja, $llave );
		}

		$wpdb->insert(
			Tablas::nombre( 'solicitudes_celular' ),
			array(
				'trabajador_id'  => $tid,
				'tipo'           => $f['tipo'],
				'estado'         => self::PENDIENTE,
				'credencial_id'  => $llave,
				'dispositivo_id' => $dispositivo,
				'navegador'      => mb_substr( Peticion::navegador(), 0, 255 ),
				'ip'             => Peticion::ip(),
				'creado_en'      => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'celular.solicitar', 'trabajador', $tid, null, array( 'tipo' => $f['tipo'], 'solicitud' => $id, 'credencial_id' => $llave, 'dispositivo_id' => $dispositivo ), '', $tid );
		Notificaciones::celular( $id );

		return array( 'tipo' => $f['tipo'], 'nombre' => (string) $t['nombres'] );
	}

	/** La persona pide liberar este celular (T0D). Si ya lo pidió, devuelve la misma solicitud. */
	public static function pedirLiberar( int $trabajadorId, int $dispositivoId ): int {
		global $wpdb;

		foreach ( self::solicitudes( $trabajadorId, self::LIBERAR, self::PENDIENTE ) as $s ) {
			if ( (int) $s['dispositivo_id'] === $dispositivoId ) {
				return (int) $s['id'];
			}
		}

		$wpdb->insert(
			Tablas::nombre( 'solicitudes_celular' ),
			array(
				'trabajador_id'  => $trabajadorId,
				'tipo'           => self::LIBERAR,
				'estado'         => self::PENDIENTE,
				'dispositivo_id' => $dispositivoId,
				'navegador'      => mb_substr( Peticion::navegador(), 0, 255 ),
				'ip'             => Peticion::ip(),
				'creado_en'      => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'celular.solicitar', 'trabajador', $trabajadorId, null, array( 'tipo' => self::LIBERAR, 'solicitud' => $id, 'dispositivo_id' => $dispositivoId ), '', $trabajadorId );
		Notificaciones::celular( $id );

		return $id;
	}

	/** @return array<string, mixed>|null */
	public static function buscar( int $id ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'solicitudes_celular' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/** @return array<string, mixed>|null La solicitud pendiente más reciente de la persona. */
	public static function pendienteDe( int $trabajadorId ): ?array {
		return self::solicitudes( $trabajadorId, null, self::PENDIENTE )[0] ?? null;
	}

	/**
	 * Solicitudes pendientes de todos, con el nombre (G13, arriba).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pendientes(): array {
		global $wpdb;

		$s = Tablas::nombre( 'solicitudes_celular' );
		$t = Tablas::nombre( 'trabajadores' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT s.*, t.nombres, t.apellidos, t.dni, t.telefono, t.cumpleanos, t.estado AS trabajador_estado FROM {$s} s JOIN {$t} t ON t.id = s.trabajador_id WHERE s.estado = %s ORDER BY s.id", self::PENDIENTE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function contarPendientes(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'solicitudes_celular' ) . ' WHERE estado = %s', self::PENDIENTE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * El celular vigente de cada persona activa (G13, abajo).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function vigentes(): array {
		global $wpdb;

		$d = Tablas::nombre( 'dispositivos' );
		$c = Tablas::nombre( 'credenciales' );
		$t = Tablas::nombre( 'trabajadores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.trabajador_id, d.user_agent, d.creado_en, d.ultimo_uso, t.nombres, t.apellidos,
					( SELECT MAX(c.ultimo_uso) FROM {$c} c WHERE c.dispositivo_id = d.id AND c.revocada_en IS NULL ) AS ultima_huella
				FROM {$d} d JOIN {$t} t ON t.id = d.trabajador_id
				WHERE d.revocado_en IS NULL AND t.estado = %s
				AND EXISTS ( SELECT 1 FROM {$c} c WHERE c.dispositivo_id = d.id AND c.revocada_en IS NULL AND c.pendiente = 0 )
				ORDER BY t.apellidos, t.nombres, d.id",
				Trabajadores::ACTIVO
			),
			ARRAY_A
		);
	}

	public static function aprobar( int $id ): true|WP_Error {
		global $wpdb;

		$s = self::buscar( $id );

		if ( ! $s || self::PENDIENTE !== $s['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'Esa solicitud ya no está pendiente.' );
		}

		$tid = (int) $s['trabajador_id'];

		if ( Trabajadores::esPropio( $tid ) ) {
			return new WP_Error( 'pka_propio', 'No puedes aprobar tu propio celular: que lo haga otra persona.' );
		}

		if ( self::REGISTRO === $s['tipo'] ) {
			$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'estado' => Trabajadores::ACTIVO, 'actualizado_en' => Peticion::ahoraUtc() ), array( 'id' => $tid ) );
		} elseif ( self::CAMBIO === $s['tipo'] ) {
			$wpdb->update( Tablas::nombre( 'credenciales' ), array( 'pendiente' => 0 ), array( 'id' => (int) $s['credencial_id'] ) );
			Dispositivos::revocarOtros( $tid, (int) $s['dispositivo_id'], (int) $s['credencial_id'] );
		} else {
			Dispositivos::revocarDispositivo( (int) $s['dispositivo_id'] );
		}

		self::cerrar( $id, self::APROBADA, '' );
		Auditoria::registrar( 'celular.aprobar', 'trabajador', $tid, null, array( 'tipo' => $s['tipo'], 'solicitud' => $id ) );
		Notificaciones::celularResuelto( $id );

		return true;
	}

	public static function rechazar( int $id, string $respuesta ): true|WP_Error {
		$s = self::buscar( $id );

		if ( ! $s || self::PENDIENTE !== $s['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'Esa solicitud ya no está pendiente.' );
		}

		$respuesta = trim( $respuesta );

		if ( '' === $respuesta ) {
			return new WP_Error( 'pka_respuesta', 'Escribe por qué se rechaza: la persona lo verá.' );
		}

		$tid = (int) $s['trabajador_id'];

		if ( self::REGISTRO === $s['tipo'] ) {
			global $wpdb;

			$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'estado' => Trabajadores::RECHAZADO, 'actualizado_en' => Peticion::ahoraUtc() ), array( 'id' => $tid ) );
			Dispositivos::revocarTodo( $tid );
		} elseif ( self::CAMBIO === $s['tipo'] ) {
			self::revocarLlaveDe( $s, 0 );
		}

		self::cerrar( $id, self::RECHAZADA, $respuesta );
		Auditoria::registrar( 'celular.rechazar', 'trabajador', $tid, null, array( 'tipo' => $s['tipo'], 'solicitud' => $id ), $respuesta );
		Notificaciones::celularResuelto( $id );

		return true;
	}

	/** Gerencia libera un celular directamente (G13B). Los datos y las horas se conservan. */
	public static function liberar( int $dispositivoId, string $motivo ): true|WP_Error {
		global $wpdb;

		$motivo = trim( $motivo );
		$d      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'dispositivos' ) . ' WHERE id = %d AND revocado_en IS NULL', $dispositivoId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $d ) {
			return new WP_Error( 'pka_no_existe', 'Ese celular ya no está registrado.' );
		}

		if ( '' === $motivo ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo: queda en el historial.' );
		}

		Dispositivos::revocarDispositivo( $dispositivoId );

		foreach ( self::solicitudes( (int) $d['trabajador_id'], self::LIBERAR, self::PENDIENTE ) as $s ) {
			if ( (int) $s['dispositivo_id'] === $dispositivoId ) {
				self::cerrar( (int) $s['id'], self::APROBADA, '' );
			}
		}

		Auditoria::registrar( 'celular.liberar', 'trabajador', (int) $d['trabajador_id'], null, array( 'dispositivo_id' => $dispositivoId ), $motivo );

		return true;
	}

	/**
	 * Texto para el perfil del trabajador (T7B) si tiene algo pendiente.
	 */
	public static function textoPendiente( int $trabajadorId ): string {
		$s = self::pendienteDe( $trabajadorId );

		return match ( $s['tipo'] ?? '' ) {
			self::CAMBIO  => 'Pediste cambiar de celular. Mientras gerencia lo aprueba, sigue marcando con tu celular anterior.',
			self::LIBERAR => 'Pediste liberar un celular. Gerencia lo revisará.',
			default       => '',
		};
	}

	/** @return array<int, array<string, mixed>> */
	private static function solicitudes( int $trabajadorId, ?string $tipo, string $estado ): array {
		global $wpdb;

		$s   = Tablas::nombre( 'solicitudes_celular' );
		$sql = $wpdb->prepare( "SELECT * FROM {$s} WHERE trabajador_id = %d AND estado = %s", $trabajadorId, $estado ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null !== $tipo ) {
			$sql .= $wpdb->prepare( ' AND tipo = %s', $tipo );
		}

		return $wpdb->get_results( $sql . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function cerrar( int $id, string $estado, string $respuesta ): void {
		global $wpdb;

		$wpdb->update(
			Tablas::nombre( 'solicitudes_celular' ),
			array(
				'estado'       => $estado,
				'respuesta'    => mb_substr( $respuesta, 0, 255 ),
				'resuelto_por' => get_current_user_id() ? get_current_user_id() : null,
				'resuelto_en'  => Peticion::ahoraUtc(),
			),
			array( 'id' => $id )
		);
	}

	/** Da de baja la llave (y el celular) de una solicitud de cambio, salvo la llave que se quiere conservar. */
	private static function revocarLlaveDe( array $s, int $conservar ): void {
		global $wpdb;

		$llave = (int) $s['credencial_id'];

		if ( $llave && $llave !== $conservar ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'credenciales' ) . ' SET revocada_en = %s WHERE id = %d AND revocada_en IS NULL', Peticion::ahoraUtc(), $llave ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$d = (int) $s['dispositivo_id'];

		// El celular se da de baja solo si ya no le queda ninguna llave vigente.
		if ( $d && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Tablas::nombre( 'credenciales' ) . ' WHERE dispositivo_id = %d AND revocada_en IS NULL', $d ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'dispositivos' ) . ' SET revocado_en = %s WHERE id = %d AND revocado_en IS NULL', Peticion::ahoraUtc(), $d ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
