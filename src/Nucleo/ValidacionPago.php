<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Validación de sueldos y de horas extra autorizadas.
 */
final class ValidacionPago {
	/** Topes de sentido común para atrapar errores de tipeo (S/ 100 000 al mes, S/ 1 000 la hora). */
	private const MAX_MENSUAL  = 10000000;
	private const MAX_POR_HORA = 100000;

	/**
	 * @param array<string, mixed> $datos
	 * @return array{modalidad: string, monto: ?int, vigente_desde: string, nota: string}
	 */
	public static function normalizarSueldo( array $datos ): array {
		return array(
			'modalidad'     => (string) ( $datos['modalidad'] ?? '' ),
			'monto'         => Sueldo::aCentimos( (string) ( $datos['monto'] ?? '' ) ),
			'vigente_desde' => trim( (string) ( $datos['vigente_desde'] ?? '' ) ),
			'nota'          => self::texto( $datos['nota'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $s           Sueldo normalizado.
	 * @param string|null          $ultimoDesde Inicio del sueldo vigente actual, si hay.
	 * @return array<string, string>
	 */
	public static function erroresSueldo( array $s, ?string $ultimoDesde = null ): array {
		$errores = array();

		if ( ! isset( Sueldo::MODALIDADES[ $s['modalidad'] ] ) ) {
			$errores['modalidad'] = 'Elige si es sueldo mensual o pago por hora.';
		}

		$tope = Sueldo::POR_HORA === $s['modalidad'] ? self::MAX_POR_HORA : self::MAX_MENSUAL;

		if ( null === $s['monto'] || $s['monto'] <= 0 ) {
			$errores['monto'] = 'Escribe el monto en soles, por ejemplo 1500 o 1500.50.';
		} elseif ( $s['monto'] > $tope ) {
			$errores['monto'] = 'El monto parece demasiado alto. Revísalo.';
		}

		if ( ! ValidacionTrabajador::esFecha( $s['vigente_desde'] ) ) {
			$errores['vigente_desde'] = 'Indica desde qué fecha rige.';
		} elseif ( null !== $ultimoDesde && $s['vigente_desde'] <= $ultimoDesde ) {
			$errores['vigente_desde'] = sprintf( 'Tiene que regir después del sueldo actual (desde el %s). Para corregir un error, anúlalo.', $ultimoDesde );
		}

		return $errores;
	}

	/**
	 * @param array<string, mixed> $datos
	 * @return array{trabajador_id: int, fecha: string, desde: string, hasta: string, compensacion: string, motivo: string}
	 */
	public static function normalizarSobretiempo( array $datos ): array {
		return array(
			'trabajador_id' => (int) ( $datos['trabajador_id'] ?? 0 ),
			'fecha'         => trim( (string) ( $datos['fecha'] ?? '' ) ),
			'desde'         => substr( trim( (string) ( $datos['desde'] ?? '' ) ), 0, 5 ),
			'hasta'         => substr( trim( (string) ( $datos['hasta'] ?? '' ) ), 0, 5 ),
			'compensacion'  => (string) ( $datos['compensacion'] ?? AutorizacionSobretiempo::PAGO ),
			'motivo'        => self::texto( $datos['motivo'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed>                              $s          Autorización normalizada.
	 * @param array<int, array{desde: string, hasta: string}>   $existentes Otras franjas vigentes del mismo trabajador y día.
	 * @return array<string, string>
	 */
	public static function erroresSobretiempo( array $s, array $existentes = array() ): array {
		$errores = array();

		if ( $s['trabajador_id'] <= 0 ) {
			$errores['trabajador_id'] = 'Elige al trabajador.';
		}

		if ( ! ValidacionTrabajador::esFecha( $s['fecha'] ) ) {
			$errores['fecha'] = 'La fecha no es válida.';
		}

		$horasOk = self::esHora( $s['desde'] ) && self::esHora( $s['hasta'] );

		if ( ! $horasOk ) {
			$errores['desde'] = 'Indica la franja con horas válidas (por ejemplo 19:30 a 21:00).';
		} elseif ( $s['desde'] === $s['hasta'] ) {
			$errores['hasta'] = 'La franja no puede durar cero minutos.';
		} elseif ( self::minutos( $s['desde'], $s['hasta'] ) > 12 * 60 ) {
			$errores['hasta'] = 'Una franja de horas extra no puede pasar de 12 horas.';
		} else {
			foreach ( $existentes as $otra ) {
				if ( self::seSolapan( $s['desde'], $s['hasta'], $otra['desde'], $otra['hasta'] ) ) {
					$errores['desde'] = sprintf( 'Se cruza con otra franja autorizada ese día (%s a %s).', substr( $otra['desde'], 0, 5 ), substr( $otra['hasta'], 0, 5 ) );
					break;
				}
			}
		}

		if ( ! isset( AutorizacionSobretiempo::COMPENSACIONES[ $s['compensacion'] ] ) ) {
			$errores['compensacion'] = 'Elige si se paga o se compensa con descanso.';
		}

		if ( mb_strlen( $s['motivo'] ) < 5 ) {
			$errores['motivo'] = 'Explica para qué son las horas extra (por ejemplo, "pedido urgente de colegio").';
		}

		return $errores;
	}

	public static function esHora( string $hora ): bool {
		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora );
	}

	/** Minutos de la franja; si termina antes de empezar, pasa la medianoche. */
	public static function minutos( string $desde, string $hasta ): int {
		$a = (int) substr( $desde, 0, 2 ) * 60 + (int) substr( $desde, 3, 2 );
		$b = (int) substr( $hasta, 0, 2 ) * 60 + (int) substr( $hasta, 3, 2 );

		return $b > $a ? $b - $a : $b + 1440 - $a;
	}

	public static function seSolapan( string $desde1, string $hasta1, string $desde2, string $hasta2 ): bool {
		$a = self::rango( $desde1, $hasta1 );
		$b = self::rango( $desde2, $hasta2 );

		return $a[0] < $b[1] && $b[0] < $a[1];
	}

	/** @return array{0: int, 1: int} */
	private static function rango( string $desde, string $hasta ): array {
		$inicio = (int) substr( $desde, 0, 2 ) * 60 + (int) substr( $desde, 3, 2 );

		return array( $inicio, $inicio + self::minutos( $desde, $hasta ) );
	}

	private static function texto( mixed $valor ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $valor ) ), 0, 255 );
	}
}
