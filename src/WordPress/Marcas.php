<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\TipoMarca;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura y registro de marcas. La tabla solo admite inserciones: las
 * correcciones aprobadas anulan marcas, pero nunca las editan ni las borran.
 */
final class Marcas {
	/**
	 * Marcas efectivas de un día laboral (sin las anuladas por correcciones aprobadas).
	 *
	 * @return Marca[]
	 */
	public static function delDia( int $trabajadorId, string $dia ): array {
		$marcas = array();

		foreach ( self::filasDelDia( $trabajadorId, $dia ) as $fila ) {
			$marcas[] = new Marca(
				TipoMarca::from( $fila['tipo'] ),
				new DateTimeImmutable( $fila['marcado_utc'], new DateTimeZone( 'UTC' ) ),
				Motivos::porClave( $fila['motivo_clave'] ),
				(int) $fila['id']
			);
		}

		return $marcas;
	}

	/**
	 * Marcas efectivas de un rango de días laborales, agrupadas, en una sola consulta.
	 *
	 * @param int[]|null $trabajadores null = todos.
	 * @return array<int, array<string, Marca[]>> trabajador_id => dia => marcas
	 */
	public static function delRango( ?array $trabajadores, string $desde, string $hasta ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$m    = Tablas::nombre( 'marcas' );
		$c    = Tablas::nombre( 'correcciones' );
		$solo = is_array( $trabajadores ) ? 'AND m.trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.trabajador_id, m.dia_laboral, m.tipo, m.motivo_clave, m.marcado_utc FROM {$m} m
				WHERE m.dia_laboral BETWEEN %s AND %s {$solo}
				AND NOT EXISTS (
					SELECT 1 FROM {$c} c WHERE c.marca_id = m.id AND c.estado = 'aprobada' AND c.accion IN ('anular', 'reemplazar')
				)
				ORDER BY m.marcado_utc, m.id",
				$desde,
				$hasta
			),
			ARRAY_A
		);

		$agrupadas = array();

		foreach ( $filas as $fila ) {
			$agrupadas[ (int) $fila['trabajador_id'] ][ $fila['dia_laboral'] ][] = new Marca(
				TipoMarca::from( $fila['tipo'] ),
				new DateTimeImmutable( $fila['marcado_utc'], new DateTimeZone( 'UTC' ) ),
				Motivos::porClave( $fila['motivo_clave'] ),
				(int) $fila['id']
			);
		}

		return $agrupadas;
	}

	/**
	 * Todas las filas de un día, incluidas las anuladas (con la columna "anulada"), para el detalle.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function filasConAnuladas( int $trabajadorId, string $dia ): array {
		global $wpdb;

		$m = Tablas::nombre( 'marcas' );
		$c = Tablas::nombre( 'correcciones' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, (
					SELECT c.justificacion FROM {$c} c WHERE c.marca_id = m.id AND c.estado = 'aprobada' AND c.accion IN ('anular', 'reemplazar') LIMIT 1
				) AS anulada
				FROM {$m} m WHERE m.trabajador_id = %d AND m.dia_laboral = %s ORDER BY m.marcado_utc, m.id",
				$trabajadorId,
				$dia
			),
			ARRAY_A
		);
	}

	/** @return array<int, array<string, mixed>> */
	public static function filasDelDia( int $trabajadorId, string $dia ): array {
		global $wpdb;

		$m = Tablas::nombre( 'marcas' );
		$c = Tablas::nombre( 'correcciones' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.* FROM {$m} m
				WHERE m.trabajador_id = %d AND m.dia_laboral = %s
				AND NOT EXISTS (
					SELECT 1 FROM {$c} c
					WHERE c.marca_id = m.id AND c.estado = 'aprobada' AND c.accion IN ('anular', 'reemplazar')
				)
				ORDER BY m.marcado_utc, m.id",
				$trabajadorId,
				$dia
			),
			ARRAY_A
		);
	}

	/**
	 * @param array{
	 *   trabajador_id: int, tipo: TipoMarca, motivo_clave: ?string, nota: string,
	 *   marcado: DateTimeImmutable, prueba: PruebaPresencia, requiere_revision: bool,
	 *   dispositivo_id: ?int, credencial_id: ?int, pantalla_id: ?int,
	 *   origen: string, correccion_id?: ?int, registrado_por?: ?int
	 * } $datos
	 */
	public static function registrar( array $datos ): int {
		$utc = $datos['marcado']->setTimezone( new DateTimeZone( 'UTC' ) );

		return InsercionEncadenada::insertar(
			'marcas',
			array(
				'trabajador_id'     => $datos['trabajador_id'],
				'dia_laboral'       => Ajustes::diaLaboral()->de( $utc ),
				'tipo'              => $datos['tipo']->value,
				'motivo_clave'      => $datos['motivo_clave'],
				'nota'              => mb_substr( $datos['nota'], 0, 255 ),
				'marcado_utc'       => $utc->format( 'Y-m-d H:i:s' ),
				'prueba'            => $datos['prueba']->value,
				'requiere_revision' => (int) $datos['requiere_revision'],
				'ip'                => Peticion::ip(),
				'dispositivo_id'    => $datos['dispositivo_id'],
				'credencial_id'     => $datos['credencial_id'],
				'pantalla_id'       => $datos['pantalla_id'],
				'origen'            => $datos['origen'],
				'correccion_id'     => $datos['correccion_id'] ?? null,
				'registrado_por'    => $datos['registrado_por'] ?? null,
			)
		);
	}

	/** Hora de Lima para mostrar ("08:58"). */
	public static function horaLocal( DateTimeImmutable $momento ): string {
		return $momento->setTimezone( Ajustes::zona() )->format( 'H:i' );
	}

	public static function etiquetaPrueba( PruebaPresencia $prueba ): string {
		return match ( $prueba ) {
			PruebaPresencia::WIFI          => 'WiFi',
			PruebaPresencia::QR            => 'QR',
			PruebaPresencia::WIFI_QR       => 'WiFi y QR',
			PruebaPresencia::SIN_PRESENCIA => 'fuera del taller',
			PruebaPresencia::MANUAL        => 'manual',
		};
	}
}
