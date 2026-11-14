<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Ausencias justificadas: vacaciones, descanso médico, licencias y permisos de días completos.
 */
final class ValidacionAusencia {
	public const TIPOS = array(
		'vacaciones'     => 'Vacaciones',
		'descanso_medico' => 'Descanso médico',
		'licencia'       => 'Licencia',
		'permiso'        => 'Permiso',
		'otro'           => 'Otra ausencia justificada',
	);

	private const MAX_DIAS = 366;

	/**
	 * @param array<string, mixed> $datos
	 * @return array{trabajador_id: int, tipo: string, desde: string, hasta: string, nota: string}
	 */
	public static function normalizar( array $datos ): array {
		return array(
			'trabajador_id' => (int) ( $datos['trabajador_id'] ?? 0 ),
			'tipo'          => (string) ( $datos['tipo'] ?? '' ),
			'desde'         => trim( (string) ( $datos['desde'] ?? '' ) ),
			'hasta'         => trim( (string) ( $datos['hasta'] ?? '' ) ),
			'nota'          => mb_substr( trim( preg_replace( '/\s+/u', ' ', (string) ( $datos['nota'] ?? '' ) ) ), 0, 255 ),
		);
	}

	/**
	 * @param array<string, mixed>                          $a          Ausencia normalizada.
	 * @param array<int, array{desde: string, hasta: string}> $existentes Otras ausencias vigentes del mismo trabajador.
	 * @return array<string, string>
	 */
	public static function errores( array $a, array $existentes = array() ): array {
		$errores = array();

		if ( $a['trabajador_id'] <= 0 ) {
			$errores['trabajador_id'] = 'Elige al trabajador.';
		}

		if ( ! isset( self::TIPOS[ $a['tipo'] ] ) ) {
			$errores['tipo'] = 'Elige el tipo de ausencia.';
		}

		$fechasOk = ValidacionTrabajador::esFecha( $a['desde'] ) && ValidacionTrabajador::esFecha( $a['hasta'] );

		if ( ! $fechasOk ) {
			$errores['desde'] = 'Las fechas no son válidas.';
		} elseif ( $a['hasta'] < $a['desde'] ) {
			$errores['hasta'] = 'La fecha final no puede ser anterior a la inicial.';
		} elseif ( self::dias( $a['desde'], $a['hasta'] ) > self::MAX_DIAS ) {
			$errores['hasta'] = 'Una ausencia no puede durar más de un año.';
		} else {
			foreach ( $existentes as $otra ) {
				if ( self::seSolapan( $a['desde'], $a['hasta'], $otra['desde'], $otra['hasta'] ) ) {
					$errores['desde'] = sprintf( 'Se cruza con otra ausencia registrada (%s a %s).', $otra['desde'], $otra['hasta'] );
					break;
				}
			}
		}

		return $errores;
	}

	public static function seSolapan( string $desde1, string $hasta1, string $desde2, string $hasta2 ): bool {
		return $desde1 <= $hasta2 && $desde2 <= $hasta1;
	}

	/** Días calendario del periodo, incluidos ambos extremos. */
	public static function dias( string $desde, string $hasta ): int {
		return ( new DateTimeImmutable( $desde ) )->diff( new DateTimeImmutable( $hasta ) )->days + 1;
	}
}
