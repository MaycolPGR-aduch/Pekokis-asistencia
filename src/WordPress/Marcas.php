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
