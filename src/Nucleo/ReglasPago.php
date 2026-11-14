<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Reglas para calcular horas y montos. Los valores por defecto siguen la norma
 * peruana (sección 6.5 del plan) y están POR CONFIRMAR CON EL CONTADOR.
 */
final class ReglasPago {
	public const TARDANZA_DESDE_ENTRADA    = 'entrada';
	public const TARDANZA_DESDE_TOLERANCIA = 'tolerancia';

	public function __construct(
		public readonly int $tasaExtra1 = 25,          // % de sobretasa de las primeras horas extra del día.
		public readonly int $tasaExtra2 = 35,          // % de sobretasa de las siguientes.
		public readonly int $tramoExtra1Min = 120,     // Cuántos minutos van a la primera tasa.
		public readonly int $tasaDescanso = 100,       // % de sobretasa por trabajar en descanso o feriado.
		public readonly int $horasJornadaValor = 8,    // Divisor del valor hora: sueldo ÷ 30 ÷ estas horas.
		public readonly bool $descontarNoTrabajado = true,
		public readonly string $tardanzaDesde = self::TARDANZA_DESDE_ENTRADA,
		public readonly int $avisoPermanenciaMin = 30, // Desde cuántos minutos sin autorizar se avisa al jefe.
	) {
	}

	/** @param array<string, mixed> $ajustes */
	public static function desdeAjustes( array $ajustes ): self {
		return new self(
			self::entero( $ajustes['tasa_extra_1'] ?? 25, 0, 300 ),
			self::entero( $ajustes['tasa_extra_2'] ?? 35, 0, 300 ),
			self::entero( $ajustes['tramo_extra_1_min'] ?? 120, 0, 1440 ),
			self::entero( $ajustes['tasa_descanso'] ?? 100, 0, 300 ),
			self::entero( $ajustes['horas_jornada_valor'] ?? 8, 1, 24 ),
			! empty( $ajustes['descontar_no_trabajado'] ),
			self::TARDANZA_DESDE_TOLERANCIA === ( $ajustes['tardanza_desde'] ?? '' ) ? self::TARDANZA_DESDE_TOLERANCIA : self::TARDANZA_DESDE_ENTRADA,
			self::entero( $ajustes['aviso_permanencia_min'] ?? 30, 1, 600 ),
		);
	}

	private static function entero( mixed $valor, int $min, int $max ): int {
		return max( $min, min( $max, (int) $valor ) );
	}
}
