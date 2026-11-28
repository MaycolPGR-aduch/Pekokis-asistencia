<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\ValidacionIdentidad;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Perfiles de los trabajadores. Cada alta, cambio y cese queda en la auditoría.
 */
final class Trabajadores {
	public const ACTIVO    = 'activo';
	public const CESADO    = 'cesado';
	// Registro propio desde el taller, esperando a gerencia; o rechazado.
	public const PENDIENTE = 'pendiente';
	public const RECHAZADO = 'rechazado';

	/** @return array<string, mixed>|null */
	public static function buscar( int $id ): ?array {
		global $wpdb;

		$tabla = Tablas::nombre( 'trabajadores' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? $fila : null;
	}

	/** @return string[] Las áreas de las personas activas, sin repetir y en orden alfabético. */
	public static function areasEnUso(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'trabajadores' );
		$areas = array();

		foreach ( (array) $wpdb->get_col( "SELECT area FROM {$tabla} WHERE estado = 'activo' AND area <> ''" ) as $texto ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( \Pekokis\Asistencia\Nucleo\ValidacionTrabajador::areas( (string) $texto ) as $a ) {
				$areas[ \Pekokis\Asistencia\Nucleo\ValidacionTrabajador::claveArea( $a ) ] ??= $a;
			}
		}

		natcasesort( $areas );

		return array_values( $areas );
	}

	/** @return array<int, array<string, mixed>> Con la columna extra "celulares" (passkeys activas). */
	public static function listar( string $estado = self::ACTIVO, string $busqueda = '' ): array {
		global $wpdb;

		$t     = Tablas::nombre( 'trabajadores' );
		$c     = Tablas::nombre( 'credenciales' );
		$where = $wpdb->prepare( 't.estado = %s', $estado );

		if ( '' !== $busqueda ) {
			$like   = '%' . $wpdb->esc_like( $busqueda ) . '%';
			$where .= $wpdb->prepare( ' AND ( t.nombres LIKE %s OR t.apellidos LIKE %s OR t.dni LIKE %s OR t.area LIKE %s )', $like, $like, $like, $like );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT t.*, ( SELECT COUNT(*) FROM {$c} c WHERE c.trabajador_id = t.id AND c.revocada_en IS NULL ) AS celulares
			FROM {$t} t WHERE {$where} ORDER BY t.apellidos, t.nombres",
			ARRAY_A
		);
	}

	/**
	 * Crea o actualiza un perfil.
	 *
	 * @param array<string, mixed> $entrada
	 * @return int|WP_Error Id del trabajador, o error con los mensajes por campo en data['errores'].
	 */
	public static function guardar( array $entrada, ?int $id = null, string $motivo = '' ): int|WP_Error {
		global $wpdb;

		$datos   = ValidacionTrabajador::normalizar( $entrada + array( 'areas_conocidas' => self::areasEnUso() ) );
		$errores = ValidacionTrabajador::errores( $datos );
		$tabla   = Tablas::nombre( 'trabajadores' );

		if ( ! isset( $errores['horario_id'] ) && ! self::existeHorario( $datos['horario_id'] ) ) {
			$errores['horario_id'] = 'Ese horario no existe.';
		}

		$otro = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabla} WHERE dni = %s AND id <> %d", $datos['dni'], (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $otro ) {
			$errores['dni'] = 'Ya hay otro trabajador con ese DNI.';
		}

		if ( $datos['user_id'] > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabla} WHERE user_id = %d AND id <> %d", $datos['user_id'], (int) $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$errores['user_id'] = 'Ese usuario ya está enlazado con otro trabajador.';
		}

		if ( $datos['user_id'] > 0 && $datos['user_id'] === $datos['jefe_user_id'] ) {
			$errores['jefe_user_id'] = 'Nadie puede ser su propio jefe: elige a otra persona para aprobar sus marcas.';
		}

		// Cumpleaños (día y mes): opcional; si se elige, tiene que existir.
		$cumpleDia = (string) ( $entrada['cumple_dia'] ?? '' );
		$cumpleMes = (string) ( $entrada['cumple_mes'] ?? '' );
		$cumple    = ValidacionIdentidad::cumpleanos( $cumpleDia, $cumpleMes );

		if ( '' === $cumple && ( '' !== $cumpleDia || '' !== $cumpleMes ) ) {
			$errores['cumpleanos'] = 'Elige un día y un mes que existan.';
		}

		if ( $errores ) {
			return new WP_Error( 'pka_datos_invalidos', 'Revisa los datos del trabajador.', array( 'errores' => $errores ) );
		}

		$fila = array(
			'dni'            => $datos['dni'],
			'nombres'        => $datos['nombres'],
			'apellidos'      => $datos['apellidos'],
			'telefono'       => $datos['telefono'],
			'correo'         => $datos['correo'],
			'cargo'          => $datos['cargo'],
			'area'           => $datos['area'],
			'jefe_user_id'   => $datos['jefe_user_id'] > 0 ? $datos['jefe_user_id'] : null,
			'user_id'        => $datos['user_id'] > 0 ? $datos['user_id'] : null,
			'horario_id'     => $datos['horario_id'],
			'fecha_ingreso'  => '' !== $datos['fecha_ingreso'] ? $datos['fecha_ingreso'] : null,
			'actualizado_en' => Peticion::ahoraUtc(),
		);

		// Un formulario sin los campos de cumpleaños (versiones anteriores, pruebas) no lo borra.
		if ( array_key_exists( 'cumple_dia', $entrada ) || array_key_exists( 'cumple_mes', $entrada ) ) {
			$fila['cumpleanos'] = $cumple;
		}

		if ( null === $id ) {
			$fila['estado']    = self::ACTIVO;
			$fila['creado_en'] = $fila['actualizado_en'];

			if ( false === $wpdb->insert( $tabla, $fila ) ) {
				return new WP_Error( 'pka_bd', 'No se pudo guardar el trabajador.' );
			}

			$id = (int) $wpdb->insert_id;
			Auditoria::registrar( 'trabajador.crear', 'trabajador', $id, null, self::paraAuditoria( $fila ), $motivo );

			return $id;
		}

		$antes = self::buscar( $id );

		if ( null === $antes ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe.' );
		}

		if ( false === $wpdb->update( $tabla, $fila, array( 'id' => $id ) ) ) {
			return new WP_Error( 'pka_bd', 'No se pudo guardar el trabajador.' );
		}

		[ $a, $d ] = Auditoria::diferencias( $antes, self::paraAuditoria( $fila ) );

		if ( $d ) {
			Auditoria::registrar( 'trabajador.editar', 'trabajador', $id, $a, $d, $motivo );
		}

		return $id;
	}

	/** Cese: el trabajador ya no puede marcar y se revocan sus celulares y passkeys. */
	public static function cesar( int $id, string $fecha, string $motivo ): true|WP_Error {
		global $wpdb;

		$antes = self::buscar( $id );

		if ( null === $antes || self::ACTIVO !== $antes['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe o ya está cesado.' );
		}

		if ( ! ValidacionTrabajador::esFecha( $fecha ) ) {
			return new WP_Error( 'pka_fecha', 'La fecha de cese no es válida.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo del cese.' );
		}

		$wpdb->update(
			Tablas::nombre( 'trabajadores' ),
			array( 'estado' => self::CESADO, 'fecha_cese' => $fecha, 'actualizado_en' => Peticion::ahoraUtc() ),
			array( 'id' => $id )
		);

		Dispositivos::revocarTodo( $id );

		Auditoria::registrar(
			'trabajador.cesar',
			'trabajador',
			$id,
			array( 'estado' => $antes['estado'] ),
			array( 'estado' => self::CESADO, 'fecha_cese' => $fecha ),
			$motivo
		);

		return true;
	}

	/**
	 * ¿Es el perfil de trabajador de quien está usando el panel? Un jefe o RR.HH.
	 * que también marca no puede aprobar ni corregir sus propias marcas.
	 */
	public static function esPropio( int $trabajadorId ): bool {
		$t = self::buscar( $trabajadorId );

		return null !== $t && (int) $t['user_id'] > 0 && (int) $t['user_id'] === get_current_user_id();
	}

	/** @param array<string, mixed> $trabajador */
	public static function nombreCompleto( array $trabajador ): string {
		return trim( $trabajador['nombres'] . ' ' . $trabajador['apellidos'] );
	}

	/** @return array<int, string> id => nombre de los horarios activos. */
	public static function horarios(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'horarios' );
		$filas = $wpdb->get_results( "SELECT id, nombre FROM {$tabla} WHERE activo = 1 ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return wp_list_pluck( $filas, 'nombre', 'id' );
	}

	private static function existeHorario( int $id ): bool {
		return isset( self::horarios()[ $id ] );
	}

	/**
	 * @param array<string, mixed> $fila
	 * @return array<string, mixed>
	 */
	private static function paraAuditoria( array $fila ): array {
		unset( $fila['creado_en'], $fila['actualizado_en'] );

		return $fila;
	}
}
