<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Limpieza y validación de un horario: nombre, tolerancia, refrigerio y,
 * para cada día de la semana (1 = lunes … 7 = domingo), si es laborable y sus horas.
 */
final class ValidacionHorario {
	public const DIAS = array( 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' );

	/**
	 * @param array<string, mixed> $datos
	 * @return array{nombre: string, tolerancia_min: int, refrigerio_min: int, dias: array<int, array{laborable: bool, entrada: ?string, salida: ?string}>}
	 */
	public static function normalizar( array $datos ): array {
		$dias = array();

		foreach ( array_keys( self::DIAS ) as $n ) {
			$d         = isset( $datos['dias'][ $n ] ) && is_array( $datos['dias'][ $n ] ) ? $datos['dias'][ $n ] : array();
			$laborable = ! empty( $d['laborable'] );

			$dias[ $n ] = array(
				'laborable' => $laborable,
				'entrada'   => $laborable ? self::hora( (string) ( $d['entrada'] ?? '' ) ) : null,
				'salida'    => $laborable ? self::hora( (string) ( $d['salida'] ?? '' ) ) : null,
			);
		}

		return array(
			'nombre'         => trim( preg_replace( '/\s+/u', ' ', (string) ( $datos['nombre'] ?? '' ) ) ),
			'tolerancia_min' => (int) ( $datos['tolerancia_min'] ?? 0 ),
			'refrigerio_min' => (int) ( $datos['refrigerio_min'] ?? 0 ),
			'dias'           => $dias,
		);
	}

	/**
	 * @param array<string, mixed> $h Horario ya normalizado.
	 * @return array<string, string> Mensaje por campo ("nombre", "dia_3", …); vacío si está bien.
	 */
	public static function errores( array $h ): array {
		$errores = array();

		if ( '' === $h['nombre'] || mb_strlen( $h['nombre'] ) > 80 ) {
			$errores['nombre'] = 'Escribe un nombre de hasta 80 caracteres.';
		}

		if ( $h['tolerancia_min'] < 0 || $h['tolerancia_min'] > 60 ) {
			$errores['tolerancia_min'] = 'La tolerancia va de 0 a 60 minutos.';
		}

		// La ley pide al menos 45 minutos de refrigerio; 0 = sin refrigerio (jornadas cortas).
		if ( 0 !== $h['refrigerio_min'] && ( $h['refrigerio_min'] < 45 || $h['refrigerio_min'] > 180 ) ) {
			$errores['refrigerio_min'] = 'El refrigerio va de 45 a 180 minutos (o 0 si no hay).';
		}

		$laborables = 0;

		foreach ( $h['dias'] as $n => $d ) {
			if ( ! $d['laborable'] ) {
				continue;
			}

			++$laborables;

			if ( null === $d['entrada'] || null === $d['salida'] ) {
				$errores[ 'dia_' . $n ] = self::DIAS[ $n ] . ': escribe la hora de entrada y la de salida.';
			} elseif ( $d['salida'] <= $d['entrada'] ) {
				$errores[ 'dia_' . $n ] = self::DIAS[ $n ] . ': la salida debe ser después de la entrada.';
			}
		}

		if ( 0 === $laborables ) {
			$errores['dias'] = 'Marca al menos un día laborable.';
		}

		return $errores;
	}

	/** Horas semanales sin refrigerio, para mostrar junto al horario (el máximo legal es 48). */
	public static function horasSemana( array $h ): float {
		$minutos = 0;

		foreach ( $h['dias'] as $d ) {
			if ( $d['laborable'] && $d['entrada'] && $d['salida'] && $d['salida'] > $d['entrada'] ) {
				$minutos += self::minutos( $d['salida'] ) - self::minutos( $d['entrada'] ) - $h['refrigerio_min'];
			}
		}

		return round( max( 0, $minutos ) / 60, 2 );
	}

	/** "9:00", "09:00" o "09:00:00" → "09:00:00"; null si no es una hora válida. */
	private static function hora( string $texto ): ?string {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::00)?$/', trim( $texto ), $m ) ) {
			return null;
		}

		return sprintf( '%02d:%s:00', (int) $m[1], $m[2] );
	}

	private static function minutos( string $hora ): int {
		return (int) substr( $hora, 0, 2 ) * 60 + (int) substr( $hora, 3, 2 );
	}
}
