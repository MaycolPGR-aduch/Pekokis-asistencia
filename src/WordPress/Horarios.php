<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ValidacionHorario;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Horarios guardados en la base de datos.
 */
final class Horarios {
	/** @var array<string, HorarioDelDia> */
	private static array $cache = array();

	public static function delDia( int $horarioId, string $dia ): HorarioDelDia {
		global $wpdb;

		// Un feriado o día no laborable del calendario manda sobre el horario.
		$feriado = Calendario::descripcion( $dia );

		if ( '' !== $feriado ) {
			return HorarioDelDia::libre( $feriado, true );
		}

		$diaSemana = (int) ( new DateTimeImmutable( $dia ) )->format( 'N' );
		$clave     = $horarioId . '|' . $diaSemana;

		if ( isset( self::$cache[ $clave ] ) ) {
			return self::$cache[ $clave ];
		}

		$h    = Tablas::nombre( 'horarios' );
		$d    = Tablas::nombre( 'horario_dias' );
		$fila = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT h.tolerancia_min, h.refrigerio_min, d.laborable, d.entrada, d.salida
				FROM {$h} h JOIN {$d} d ON d.horario_id = h.id WHERE h.id = %d AND d.dia_semana = %d",
				$horarioId,
				$diaSemana
			),
			ARRAY_A
		);

		self::$cache[ $clave ] = $fila
			? new HorarioDelDia( (bool) $fila['laborable'], $fila['entrada'], $fila['salida'], (int) $fila['tolerancia_min'], (int) $fila['refrigerio_min'] )
			: HorarioDelDia::libre();

		return self::$cache[ $clave ];
	}

	public static function limpiarCache(): void {
		self::$cache    = array();
		self::$cambios  = null;
		self::$margenes = array();
	}

	/** @var array<int, array<int, array<string, mixed>>>|null Cambios vigentes por trabajador, el más nuevo primero. */
	private static ?array $cambios = null;

	/** @var array<int, array{0: int, 1: int}> Tolerancia y refrigerio de cada horario. */
	private static array $margenes = array();

	/**
	 * Horario de una persona en un día (maqueta G7): un cambio por un día o un
	 * periodo manda sobre su horario base. En un feriado el día sigue siendo
	 * feriado para el pago, aunque se trabaje con el horario del cambio.
	 *
	 * @param array<string, mixed> $trabajador Fila con id y horario_id.
	 */
	public static function deTrabajador( array $trabajador, string $dia ): HorarioDelDia {
		$cambio = self::cambioDe( (int) $trabajador['id'], $dia );

		if ( null === $cambio ) {
			return self::delDia( (int) $trabajador['horario_id'], $dia );
		}

		$feriado = Calendario::descripcion( $dia );

		if ( ! (int) $cambio['laborable'] ) {
			return HorarioDelDia::libre( '' !== $feriado ? $feriado : 'Libre por cambio de horario', '' !== $feriado );
		}

		[ $tolerancia, $refrigerio ] = self::margenes( (int) $trabajador['horario_id'] );

		return new HorarioDelDia( true, (string) $cambio['entrada'], (string) $cambio['salida'], $tolerancia, $refrigerio, $feriado, '' !== $feriado );
	}

	/** @return array<string, mixed>|null El cambio vigente que cubre ese día. */
	public static function cambioDe( int $trabajadorId, string $dia ): ?array {
		if ( null === self::$cambios ) {
			global $wpdb;

			self::$cambios = array();
			$tabla         = Tablas::nombre( 'horario_cambios' );

			foreach ( $wpdb->get_results( "SELECT * FROM {$tabla} WHERE estado = 'vigente' ORDER BY id DESC", ARRAY_A ) as $fila ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::$cambios[ (int) $fila['trabajador_id'] ][] = $fila;
			}
		}

		foreach ( self::$cambios[ $trabajadorId ] ?? array() as $c ) {
			if ( $c['desde'] <= $dia && $dia <= $c['hasta'] ) {
				return $c;
			}
		}

		return null;
	}

	/**
	 * Cambios vigentes que terminan hoy o después, con el nombre de la persona.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cambiosProximos( string $desde ): array {
		global $wpdb;

		$c = Tablas::nombre( 'horario_cambios' );
		$t = Tablas::nombre( 'trabajadores' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT c.*, t.nombres, t.apellidos FROM {$c} c JOIN {$t} t ON t.id = c.trabajador_id WHERE c.estado = 'vigente' AND c.hasta >= %s ORDER BY c.desde, t.apellidos", $desde ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Cambio de horario de una persona por un día o un periodo.
	 *
	 * @param array<string, mixed> $entrada trabajador_id, desde, hasta, laborable, entrada, salida, motivo.
	 * @return int|WP_Error Id del cambio, o errores por campo.
	 */
	public static function crearCambio( array $entrada ): int|WP_Error {
		global $wpdb;

		$datos = array(
			'trabajador_id' => (int) ( $entrada['trabajador_id'] ?? 0 ),
			'desde'         => (string) ( $entrada['desde'] ?? '' ),
			'hasta'         => (string) ( $entrada['hasta'] ?? '' ),
			'laborable'     => empty( $entrada['libre'] ) ? 1 : 0,
			'entrada'       => substr( (string) ( $entrada['entrada'] ?? '' ), 0, 5 ),
			'salida'        => substr( (string) ( $entrada['salida'] ?? '' ), 0, 5 ),
			'motivo'        => trim( mb_substr( (string) ( $entrada['motivo'] ?? '' ), 0, 255 ) ),
		);

		if ( '' === $datos['hasta'] ) {
			$datos['hasta'] = $datos['desde'];
		}

		$errores = array();
		$esFecha = static fn( string $f ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) && false !== DateTimeImmutable::createFromFormat( '!Y-m-d', $f );
		$esHora  = static fn( string $h ): bool => (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $h );

		if ( null === Trabajadores::buscar( $datos['trabajador_id'] ) ) {
			$errores['trabajador_id'] = 'Elige a la persona.';
		}

		if ( ! $esFecha( $datos['desde'] ) ) {
			$errores['desde'] = 'Elige el día.';
		} elseif ( ! $esFecha( $datos['hasta'] ) || $datos['hasta'] < $datos['desde'] ) {
			$errores['hasta'] = 'El último día no puede ser antes del primero.';
		} elseif ( ( new DateTimeImmutable( $datos['desde'] ) )->diff( new DateTimeImmutable( $datos['hasta'] ) )->days > 366 ) {
			$errores['hasta'] = 'Un cambio dura como máximo un año.';
		}

		if ( $datos['laborable'] ) {
			if ( ! $esHora( $datos['entrada'] ) || ! $esHora( $datos['salida'] ) ) {
				$errores['entrada'] = 'Escribe la hora de entrada y la de salida.';
			} elseif ( $datos['salida'] <= $datos['entrada'] ) {
				$errores['entrada'] = 'La salida tiene que ser después de la entrada.';
			}
		} else {
			$datos['entrada'] = null;
			$datos['salida']  = null;
		}

		if ( '' === $datos['motivo'] ) {
			$errores['motivo'] = 'Escribe el motivo: queda en la auditoría.';
		}

		if ( $errores ) {
			return new WP_Error( 'pka_cambio_horario', 'Revisa el cambio de horario.', array( 'errores' => $errores ) );
		}

		$wpdb->insert( Tablas::nombre( 'horario_cambios' ), $datos + array( 'estado' => 'vigente', 'creado_por' => get_current_user_id(), 'creado_en' => Peticion::ahoraUtc() ) );
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar(
			'horario.cambio',
			'trabajador',
			$datos['trabajador_id'],
			null,
			array(
				'desde'   => $datos['desde'],
				'hasta'   => $datos['hasta'],
				'horario_dia' => $datos['laborable'] ? $datos['entrada'] . '–' . $datos['salida'] : 'Libre',
			),
			$datos['motivo']
		);
		self::limpiarCache();
		Tardanzas::resincronizar( array( $datos['trabajador_id'] ), $datos['desde'], $datos['hasta'] );

		return $id;
	}

	public static function anularCambio( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		$tabla = Tablas::nombre( 'horario_cambios' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese cambio no existe o ya está anulado.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update(
			$tabla,
			array( 'estado' => 'anulado', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ),
			array( 'id' => $id )
		);

		Auditoria::registrar(
			'horario.cambio_anular',
			'trabajador',
			(int) $fila['trabajador_id'],
			array(
				'desde'   => $fila['desde'],
				'hasta'   => $fila['hasta'],
				'horario_dia' => (int) $fila['laborable'] ? substr( (string) $fila['entrada'], 0, 5 ) . '–' . substr( (string) $fila['salida'], 0, 5 ) : 'Libre',
			),
			null,
			$motivo
		);
		self::limpiarCache();
		Tardanzas::resincronizar( array( (int) $fila['trabajador_id'] ), (string) $fila['desde'], (string) $fila['hasta'] );

		return true;
	}

	/**
	 * Cambia el horario base de una persona (maqueta G7, columna "Horario base").
	 */
	public static function asignar( int $trabajadorId, int $horarioId, string $motivo ): true|WP_Error {
		global $wpdb;

		$t = Trabajadores::buscar( $trabajadorId );

		if ( null === $t || ! isset( Trabajadores::horarios()[ $horarioId ] ) ) {
			return new WP_Error( 'pka_horario', 'Ese horario o esa persona no existen.' );
		}

		if ( (int) $t['horario_id'] === $horarioId ) {
			return true;
		}

		$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'horario_id' => $horarioId, 'actualizado_en' => Peticion::ahoraUtc() ), array( 'id' => $trabajadorId ) );
		Auditoria::registrar( 'trabajador.editar', 'trabajador', $trabajadorId, array( 'horario_id' => (int) $t['horario_id'] ), array( 'horario_id' => $horarioId ), $motivo );

		return true;
	}

	/** @return array{0: int, 1: int} */
	private static function margenes( int $horarioId ): array {
		if ( ! isset( self::$margenes[ $horarioId ] ) ) {
			global $wpdb;

			$h   = Tablas::nombre( 'horarios' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT tolerancia_min, refrigerio_min FROM {$h} WHERE id = %d", $horarioId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			self::$margenes[ $horarioId ] = $row ? array( (int) $row['tolerancia_min'], (int) $row['refrigerio_min'] ) : array( 5, 60 );
		}

		return self::$margenes[ $horarioId ];
	}


	/** @return array<int, array<string, mixed>> Horarios con la cantidad de trabajadores activos que los usan. */
	public static function listar(): array {
		global $wpdb;

		$h = Tablas::nombre( 'horarios' );
		$t = Tablas::nombre( 'trabajadores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT h.*, ( SELECT COUNT(*) FROM {$t} t WHERE t.horario_id = h.id AND t.estado = 'activo' ) AS trabajadores
			FROM {$h} h ORDER BY h.activo DESC, h.nombre",
			ARRAY_A
		);
	}

	/**
	 * Horario con sus 7 días, en el formato de ValidacionHorario.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function completo( int $id ): ?array {
		global $wpdb;

		$h    = Tablas::nombre( 'horarios' );
		$d    = Tablas::nombre( 'horario_dias' );
		$fila = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$h} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return null;
		}

		$dias = array();

		foreach ( array_keys( ValidacionHorario::DIAS ) as $n ) {
			$dias[ $n ] = array( 'laborable' => false, 'entrada' => null, 'salida' => null );
		}

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$d} WHERE horario_id = %d", $id ), ARRAY_A ) as $dia ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dias[ (int) $dia['dia_semana'] ] = array(
				'laborable' => (bool) $dia['laborable'],
				'entrada'   => $dia['entrada'],
				'salida'    => $dia['salida'],
			);
		}

		return array(
			'id'             => (int) $fila['id'],
			'nombre'         => $fila['nombre'],
			'tolerancia_min' => (int) $fila['tolerancia_min'],
			'refrigerio_min' => (int) $fila['refrigerio_min'],
			'activo'         => (bool) $fila['activo'],
			'dias'           => $dias,
		);
	}

	/**
	 * Crea o actualiza un horario. Los cambios quedan en el historial con un resumen legible.
	 *
	 * @param array<string, mixed> $entrada
	 * @return int|WP_Error
	 */
	public static function guardar( array $entrada, ?int $id, string $motivo = '' ): int|WP_Error {
		global $wpdb;

		$datos   = ValidacionHorario::normalizar( $entrada );
		$errores = ValidacionHorario::errores( $datos );

		if ( $errores ) {
			return new WP_Error( 'pka_horario', 'Revisa el horario.', array( 'errores' => $errores ) );
		}

		$antes = null;

		if ( null !== $id ) {
			$antes = self::completo( $id );

			if ( null === $antes ) {
				return new WP_Error( 'pka_no_existe', 'Ese horario no existe.' );
			}

			if ( '' === trim( $motivo ) ) {
				return new WP_Error( 'pka_motivo', 'Escribe el motivo del cambio: afecta el cálculo de tardanzas.', array( 'errores' => array( 'motivo' => 'Escribe el motivo del cambio.' ) ) );
			}
		}

		$h      = Tablas::nombre( 'horarios' );
		$d      = Tablas::nombre( 'horario_dias' );
		$cabeza = array( 'nombre' => $datos['nombre'], 'tolerancia_min' => $datos['tolerancia_min'], 'refrigerio_min' => $datos['refrigerio_min'] );

		if ( null === $id ) {
			$wpdb->insert( $h, $cabeza + array( 'activo' => 1, 'creado_en' => Peticion::ahoraUtc() ) );
			$id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( $h, $cabeza, array( 'id' => $id ) );
		}

		foreach ( $datos['dias'] as $n => $dia ) {
			$wpdb->replace(
				$d,
				array( 'horario_id' => $id, 'dia_semana' => $n, 'laborable' => (int) $dia['laborable'], 'entrada' => $dia['entrada'], 'salida' => $dia['salida'] ),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}

		self::$cache = array();

		$resumenAntes = $antes ? self::resumen( $antes ) : null;
		$resumen      = self::resumen( $datos );

		if ( null === $antes ) {
			Auditoria::registrar( 'horario.crear', 'horario', $id, null, $resumen, $motivo );
		} else {
			[ $a, $dd ] = Auditoria::diferencias( $resumenAntes, $resumen );

			if ( $dd ) {
				Auditoria::registrar( 'horario.editar', 'horario', $id, $a, $dd, $motivo );
			}
		}

		return $id;
	}

	/** Desactiva un horario que ya nadie usa. */
	public static function cambiarEstado( int $id, bool $activo ): true|WP_Error {
		global $wpdb;

		$fila = array_values( array_filter( self::listar(), static fn( array $h ): bool => (int) $h['id'] === $id ) )[0] ?? null;

		if ( null === $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese horario no existe.' );
		}

		if ( ! $activo && (int) $fila['trabajadores'] > 0 ) {
			return new WP_Error( 'pka_en_uso', 'No se puede desactivar: lo usan ' . (int) $fila['trabajadores'] . ' trabajador(es). Asígnales otro horario primero.' );
		}

		$wpdb->update( Tablas::nombre( 'horarios' ), array( 'activo' => (int) $activo ), array( 'id' => $id ) );
		Auditoria::registrar( $activo ? 'horario.activar' : 'horario.desactivar', 'horario', $id );

		return true;
	}

	/**
	 * Resumen plano para el historial: "Lunes" => "09:00–19:30", "Sábado" => "libre"…
	 *
	 * @param array<string, mixed> $h
	 * @return array<string, mixed>
	 */
	private static function resumen( array $h ): array {
		$r = array( 'nombre' => $h['nombre'], 'tolerancia_min' => $h['tolerancia_min'], 'refrigerio_min' => $h['refrigerio_min'] );

		foreach ( ValidacionHorario::DIAS as $n => $nombreDia ) {
			$dia             = $h['dias'][ $n ];
			$r[ $nombreDia ] = $dia['laborable'] ? substr( (string) $dia['entrada'], 0, 5 ) . '–' . substr( (string) $dia['salida'], 0, 5 ) : 'libre';
		}

		return $r;
	}
}
