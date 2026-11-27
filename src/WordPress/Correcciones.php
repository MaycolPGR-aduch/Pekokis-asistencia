<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Accion;
use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\MaquinaEstados;
use Pekokis\Asistencia\Nucleo\PasosDelDia;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Correcciones del registro. Nunca se edita ni se borra una marca:
 *
 * - "agregar": crea una marca nueva con origen "correccion" (pedida por el
 *   trabajador y aprobada por el jefe) o "manual" (registrada por el jefe).
 * - "anular": deja la marca original en la tabla, pero deja de contar.
 *
 * Antes de aplicar se revisa que el día quede con una secuencia lógica.
 */
final class Correcciones {
	public const AGREGAR   = 'agregar';
	public const ANULAR    = 'anular';
	public const PENDIENTE = 'pendiente';
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';

	private const DIAS_ATRAS = 31;

	/** El trabajador avisa que olvidó marcar. Queda pendiente para su jefe. */
	public static function solicitar( int $trabajadorId, string $fecha, string $hora, Accion $accion, ?string $motivo, string $justificacion ): int|WP_Error {
		$datos = self::prepararAgregar( $trabajadorId, $fecha, $hora, $accion, $motivo, $justificacion );

		if ( $datos instanceof WP_Error ) {
			return $datos;
		}

		$id = self::insertar( $datos + array( 'solicitado_por_trabajador' => $trabajadorId, 'estado' => self::PENDIENTE ) );
		Auditoria::registrar( 'correccion.solicitar', 'correccion', $id, null, self::paraAuditoria( $datos ), $justificacion, $trabajadorId );
		Notificaciones::solicitud( $id );

		return $id;
	}

	/** El jefe registra una marca que faltó. Se aplica en el acto. */
	public static function marcaManual( int $trabajadorId, string $fecha, string $hora, Accion $accion, ?string $motivo, string $justificacion ): int|WP_Error {
		$datos = self::prepararAgregar( $trabajadorId, $fecha, $hora, $accion, $motivo, $justificacion );

		if ( $datos instanceof WP_Error ) {
			return $datos;
		}

		$problema = self::problemaAlAgregar( $datos );

		if ( null !== $problema ) {
			return new WP_Error( 'pka_secuencia', $problema );
		}

		$id = self::insertar(
			$datos + array(
				'solicitado_por_user' => get_current_user_id(),
				'estado'              => self::APROBADA,
				'resuelto_por'        => get_current_user_id(),
				'resuelto_en'         => Peticion::ahoraUtc(),
			)
		);

		$marca = self::registrarMarca( $datos, $id, 'manual' );
		Auditoria::registrar( 'marca.manual', 'marca', $marca, null, self::paraAuditoria( $datos ), $justificacion );

		return $marca;
	}

	/** El jefe anula una marca equivocada. La marca queda guardada, pero deja de contar. */
	public static function anular( int $marcaId, string $justificacion ): true|WP_Error {
		global $wpdb;

		if ( '' === trim( $justificacion ) ) {
			return new WP_Error( 'pka_justificacion', 'Escribe por qué se anula la marca.' );
		}

		$tabla = Tablas::nombre( 'marcas' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $marcaId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Esa marca no existe.' );
		}

		$restantes = array_filter( Marcas::delDia( (int) $fila['trabajador_id'], $fila['dia_laboral'] ), static fn( Marca $m ): bool => $m->id !== $marcaId );

		if ( count( $restantes ) === count( Marcas::delDia( (int) $fila['trabajador_id'], $fila['dia_laboral'] ) ) ) {
			return new WP_Error( 'pka_anulada', 'Esa marca ya estaba anulada.' );
		}

		$problema = MaquinaEstados::problemaEnSecuencia( $restantes, Ajustes::zona() );

		if ( null !== $problema ) {
			return new WP_Error( 'pka_secuencia', 'Si se anula, el día queda mal: ' . $problema . ' Anula también la marca que la acompaña.' );
		}

		$id = self::insertar(
			array(
				'trabajador_id'       => (int) $fila['trabajador_id'],
				'accion'              => self::ANULAR,
				'marca_id'            => $marcaId,
				'justificacion'       => mb_substr( $justificacion, 0, 500 ),
				'solicitado_por_user' => get_current_user_id(),
				'estado'              => self::APROBADA,
				'resuelto_por'        => get_current_user_id(),
				'resuelto_en'         => Peticion::ahoraUtc(),
			)
		);

		Auditoria::registrar( 'marca.anular', 'marca', $marcaId, array( 'tipo' => $fila['tipo'], 'marcado_utc' => $fila['marcado_utc'] ), array( 'correccion_id' => $id ), $justificacion );
		Tardanzas::sincronizar( (int) $fila['trabajador_id'], (string) $fila['dia_laboral'] );

		return true;
	}

	/**
	 * Edita los cuatro pasos de un día de una sola vez (maquetas G4B): entrada,
	 * salida a comer, vuelta y salida, en "HH:MM" ('' = sin esa marca).
	 *
	 * Primero se arma el día como quedaría y se valida entero; recién entonces se
	 * anulan las marcas que cambian y se agregan las nuevas (las marcas nunca se
	 * editan ni se borran). Una hora menor que la de entrada se toma del día
	 * siguiente (jornada que pasó la medianoche). Queda una sola entrada en la
	 * auditoría con el antes, el después y el motivo.
	 *
	 * @param array<string, string> $horas paso => "HH:MM" o ''.
	 */
	public static function editarDia( int $trabajadorId, string $dia, array $horas, string $justificacion ): true|WP_Error {
		$justificacion = trim( $justificacion );

		if ( '' === $justificacion ) {
			return new WP_Error( 'pka_justificacion', 'Escribe el motivo del cambio: queda en la auditoría.' );
		}

		if ( ! ValidacionTrabajador::esFecha( $dia ) ) {
			return new WP_Error( 'pka_fecha', 'El día no es válido.' );
		}

		$zona   = Ajustes::zona();
		$marcas = Marcas::delDia( $trabajadorId, $dia );
		$pasos  = PasosDelDia::de( $marcas );
		$hora   = static fn( ?Marca $m ): string => $m ? $m->marcadoUtc->setTimezone( $zona )->format( 'H:i' ) : '';
		$final  = $marcas;
		$quitar = array();
		$poner  = array();
		$antes  = array();
		$despues = array();

		$entrada = trim( (string) ( $horas[ PasosDelDia::ENTRADA ] ?? $hora( $pasos[ PasosDelDia::ENTRADA ] ) ) );

		foreach ( PasosDelDia::TIPOS as $paso => [ $tipo, $motivo ] ) {
			$viejo = $hora( $pasos[ $paso ] );
			$nuevo = trim( (string) ( $horas[ $paso ] ?? $viejo ) );

			if ( '' !== $nuevo && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $nuevo ) ) {
				return new WP_Error( 'pka_hora', 'La hora de "' . PasosDelDia::NOMBRES[ $paso ] . '" no es válida.' );
			}

			if ( $nuevo === $viejo ) {
				continue;
			}

			$antes[ $paso ]   = '' !== $viejo ? $viejo : 'sin marca';
			$despues[ $paso ] = '' !== $nuevo ? $nuevo : 'sin marca';

			if ( $pasos[ $paso ] ) {
				$quitar[] = $pasos[ $paso ];
				$final    = array_values( array_filter( $final, static fn( Marca $m ): bool => $m !== $pasos[ $paso ] ) );
			}

			if ( '' !== $nuevo ) {
				// Pasada la medianoche: la hora es del día siguiente.
				$fecha   = PasosDelDia::ENTRADA !== $paso && '' !== $entrada && $nuevo < $entrada ? ( new DateTimeImmutable( $dia ) )->modify( '+1 day' )->format( 'Y-m-d' ) : $dia;
				$momento = self::momento( $fecha, $nuevo );

				if ( $momento instanceof WP_Error ) {
					return $momento;
				}

				$marca   = new Marca( $tipo, $momento, null === $motivo ? null : Motivos::porClave( $motivo ) );
				$final[] = $marca;
				$poner[] = $marca;
			}
		}

		if ( ! $antes ) {
			return new WP_Error( 'pka_sin_cambios', 'No cambiaste ninguna hora.' );
		}

		$problema = MaquinaEstados::problemaEnSecuencia( $final, $zona );

		if ( null !== $problema ) {
			return new WP_Error( 'pka_secuencia', 'Así el día queda mal: ' . $problema );
		}

		$momentos = array_map( static fn( Marca $m ): int => $m->marcadoUtc->getTimestamp(), $final );

		if ( count( $momentos ) !== count( array_unique( $momentos ) ) ) {
			return new WP_Error( 'pka_secuencia', 'Dos marcas quedarían a la misma hora.' );
		}

		$usuario = get_current_user_id();
		$ahora   = Peticion::ahoraUtc();

		foreach ( $quitar as $m ) {
			self::insertar( array( 'trabajador_id' => $trabajadorId, 'accion' => self::ANULAR, 'marca_id' => $m->id, 'justificacion' => mb_substr( $justificacion, 0, 500 ), 'solicitado_por_user' => $usuario, 'estado' => self::APROBADA, 'resuelto_por' => $usuario, 'resuelto_en' => $ahora ) );
		}

		foreach ( $poner as $m ) {
			$c  = array( 'trabajador_id' => $trabajadorId, 'accion' => self::AGREGAR, 'tipo' => $m->tipo->value, 'motivo_clave' => $m->motivo?->clave, 'marcado_utc' => $m->marcadoUtc->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'justificacion' => mb_substr( $justificacion, 0, 500 ) );
			$id = self::insertar( $c + array( 'solicitado_por_user' => $usuario, 'estado' => self::APROBADA, 'resuelto_por' => $usuario, 'resuelto_en' => $ahora ) );
			self::registrarMarca( $c, $id, 'manual', $dia );
		}

		Auditoria::registrar( 'marca.editar_dia', 'trabajador', $trabajadorId, array( 'dia' => $dia ) + $antes, array( 'dia' => $dia ) + $despues, $justificacion );
		Tardanzas::sincronizar( $trabajadorId, $dia );

		return true;
	}

	/**
	 * Aprueba la solicitud. Con $horaFinal ("HH:MM", maquetas G6B) se aprueba con
	 * otra hora: se guarda la que pidió y el trabajador ve "Aprobada con corrección".
	 */
	public static function aprobar( int $id, string $nota, string $horaFinal = '' ): true|WP_Error {
		global $wpdb;

		$c = self::pendiente( $id );

		if ( $c instanceof WP_Error ) {
			return $c;
		}

		$pedida = new DateTimeImmutable( $c['marcado_utc'], new DateTimeZone( 'UTC' ) );
		$local  = $pedida->setTimezone( Ajustes::zona() );

		if ( '' !== $horaFinal && $horaFinal !== $local->format( 'H:i' ) ) {
			// Misma fecha del reloj que la hora pedida (una salida de madrugada sigue en su madrugada).
			$final = self::momento( $local->format( 'Y-m-d' ), $horaFinal );

			if ( $final instanceof WP_Error ) {
				return $final;
			}

			$c['marcado_utc'] = $final->format( 'Y-m-d H:i:s' );
			$problema         = self::problemaAlAgregar( $c );

			if ( null !== $problema ) {
				return new WP_Error( 'pka_secuencia', 'No se puede aprobar con esa hora: ' . $problema );
			}

			$wpdb->update( Tablas::nombre( 'correcciones' ), array( 'marcado_utc' => $c['marcado_utc'], 'hora_pedida_utc' => $pedida->format( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
			$nota = '' !== trim( $nota ) ? $nota : 'Aprobada con la hora ' . $horaFinal . ' (pediste ' . $local->format( 'H:i' ) . ').';
		}

		$problema = self::problemaAlAgregar( $c );

		if ( null !== $problema ) {
			return new WP_Error( 'pka_secuencia', 'No se puede aprobar: ' . $problema . ' Revisa el día del trabajador.' );
		}

		self::resolver( $id, self::APROBADA, $nota );
		$marca = self::registrarMarca( $c, $id, 'correccion' );
		Auditoria::registrar( 'correccion.aprobar', 'correccion', $id, null, array( 'marca_id' => $marca ), $nota );
		Notificaciones::resuelta( $id );

		return true;
	}

	public static function rechazar( int $id, string $nota ): true|WP_Error {
		$c = self::pendiente( $id );

		if ( $c instanceof WP_Error ) {
			return $c;
		}

		if ( '' === trim( $nota ) ) {
			return new WP_Error( 'pka_nota', 'Para rechazar, escribe el motivo.' );
		}

		self::resolver( $id, self::RECHAZADA, $nota );
		Auditoria::registrar( 'correccion.rechazar', 'correccion', $id, null, null, $nota );
		Notificaciones::resuelta( $id );

		return true;
	}

	/**
	 * @param int[]|null $trabajadores
	 * @return array<int, array<string, mixed>>
	 */
	public static function pendientes( ?array $trabajadores ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$c    = Tablas::nombre( 'correcciones' );
		$t    = Tablas::nombre( 'trabajadores' );
		$solo = is_array( $trabajadores ) ? 'AND c.trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT c.*, t.nombres, t.apellidos FROM {$c} c JOIN {$t} t ON t.id = c.trabajador_id
			WHERE c.estado = 'pendiente' {$solo} ORDER BY c.creado_en",
			ARRAY_A
		);
	}

	/**
	 * Hora local de Lima convertida a UTC; no se aceptan horas futuras ni de hace más de un mes.
	 */
	public static function momento( string $fecha, string $hora ): DateTimeImmutable|WP_Error {
		if ( ! ValidacionTrabajador::esFecha( $fecha ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora ) ) {
			return new WP_Error( 'pka_hora', 'La fecha o la hora no son válidas.' );
		}

		$momento = ( new DateTimeImmutable( $fecha . ' ' . $hora . ':00', Ajustes::zona() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$ahora   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		if ( $momento > $ahora ) {
			return new WP_Error( 'pka_futuro', 'No se puede registrar una hora que todavía no llega.' );
		}

		if ( $momento < $ahora->modify( '-' . self::DIAS_ATRAS . ' days' ) ) {
			return new WP_Error( 'pka_antiguo', 'Solo se corrigen marcas del último mes.' );
		}

		return $momento;
	}

	/** @return array<string, mixed>|WP_Error */
	private static function prepararAgregar( int $trabajadorId, string $fecha, string $hora, Accion $accion, ?string $motivo, string $justificacion ): array|WP_Error {
		$trabajador = Trabajadores::buscar( $trabajadorId );

		if ( null === $trabajador || Trabajadores::ACTIVO !== $trabajador['estado'] ) {
			return new WP_Error( 'pka_trabajador', 'Ese trabajador no existe o no está activo.' );
		}

		if ( '' === trim( $justificacion ) ) {
			return new WP_Error( 'pka_justificacion', 'Cuenta brevemente qué pasó.' );
		}

		$momento = self::momento( $fecha, $hora );

		if ( $momento instanceof WP_Error ) {
			return $momento;
		}

		$motivoObj = Motivos::paraAccion( $accion, $motivo );

		if ( TipoMarca::SALIDA === $accion->tipo() && null === $motivoObj ) {
			return new WP_Error( 'pka_motivo', 'Elige el motivo de la salida.' );
		}

		return array(
			'trabajador_id' => $trabajadorId,
			'accion'        => self::AGREGAR,
			'tipo'          => $accion->tipo()->value,
			'motivo_clave'  => $motivoObj?->clave,
			'marcado_utc'   => $momento->format( 'Y-m-d H:i:s' ),
			'justificacion' => mb_substr( sanitize_text_field( $justificacion ), 0, 500 ),
		);
	}

	/** @param array<string, mixed> $c Corrección de tipo "agregar". */
	private static function problemaAlAgregar( array $c ): ?string {
		$momento = new DateTimeImmutable( $c['marcado_utc'], new DateTimeZone( 'UTC' ) );
		$marcas  = Marcas::delDia( (int) $c['trabajador_id'], Ajustes::diaLaboral()->de( $momento ) );

		foreach ( $marcas as $m ) {
			if ( $m->marcadoUtc == $momento ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- compara instantes.
				return 'Ya hay una marca exactamente a esa hora.';
			}
		}

		$marcas[] = new Marca( TipoMarca::from( $c['tipo'] ), $momento, Motivos::porClave( $c['motivo_clave'] ) );

		return MaquinaEstados::problemaEnSecuencia( $marcas, Ajustes::zona() );
	}

	/** @param array<string, mixed> $c */
	private static function registrarMarca( array $c, int $correccionId, string $origen, ?string $dia = null ): int {
		return Marcas::registrar(
			array(
				'trabajador_id'     => (int) $c['trabajador_id'],
				'dia_laboral'       => $dia ?? Ajustes::diaLaboral()->de( new DateTimeImmutable( $c['marcado_utc'], new DateTimeZone( 'UTC' ) ) ),
				'tipo'              => TipoMarca::from( $c['tipo'] ),
				'motivo_clave'      => $c['motivo_clave'],
				'nota'              => (string) $c['justificacion'],
				'marcado'           => new DateTimeImmutable( $c['marcado_utc'], new DateTimeZone( 'UTC' ) ),
				'prueba'            => PruebaPresencia::MANUAL,
				'requiere_revision' => false,
				'dispositivo_id'    => null,
				'credencial_id'     => null,
				'pantalla_id'       => null,
				'origen'            => $origen,
				'correccion_id'     => $correccionId,
				'registrado_por'    => get_current_user_id() ?: null,
			)
		);
	}

	/** @return array<string, mixed>|WP_Error */
	private static function pendiente( int $id ): array|WP_Error {
		global $wpdb;

		$tabla = Tablas::nombre( 'correcciones' );
		$c     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'pendiente' AND accion = 'agregar'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $c ? $c : new WP_Error( 'pka_no_pendiente', 'Esa solicitud ya fue resuelta o no existe.' );
	}

	private static function resolver( int $id, string $estado, string $nota ): void {
		global $wpdb;

		$wpdb->update(
			Tablas::nombre( 'correcciones' ),
			array( 'estado' => $estado, 'resuelto_por' => get_current_user_id(), 'resuelto_en' => Peticion::ahoraUtc(), 'nota_resolucion' => mb_substr( $nota, 0, 500 ) ),
			array( 'id' => $id )
		);
	}

	/** @param array<string, mixed> $datos */
	private static function insertar( array $datos ): int {
		global $wpdb;

		$wpdb->insert( Tablas::nombre( 'correcciones' ), $datos + array( 'creado_en' => Peticion::ahoraUtc() ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $datos
	 * @return array<string, mixed>
	 */
	private static function paraAuditoria( array $datos ): array {
		return array_intersect_key( $datos, array_flip( array( 'tipo', 'motivo_clave', 'marcado_utc' ) ) );
	}
}
