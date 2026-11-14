<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Un sueldo con su vigencia. Los montos van en céntimos para no arrastrar
 * errores de redondeo. Cada cambio crea uno nuevo; el historial no se edita.
 */
final class Sueldo {
	public const MENSUAL  = 'mensual';
	public const POR_HORA = 'por_hora';

	public const MODALIDADES = array(
		self::MENSUAL  => 'Sueldo mensual',
		self::POR_HORA => 'Pago por hora',
	);

	public function __construct(
		public readonly string $modalidad,
		public readonly int $montoCentimos,
		public readonly string $vigenteDesde,       // Y-m-d
		public readonly ?string $vigenteHasta = null, // Y-m-d, null = sigue vigente.
		public readonly ?int $id = null,
	) {
	}

	public function rigeEn( string $dia ): bool {
		return $this->vigenteDesde <= $dia && ( null === $this->vigenteHasta || $dia <= $this->vigenteHasta );
	}

	/** Valor de una hora en céntimos (con decimales: se redondea al final). */
	public function valorHora( ReglasPago $reglas ): float {
		return self::POR_HORA === $this->modalidad
			? (float) $this->montoCentimos
			: $this->montoCentimos / 30 / $reglas->horasJornadaValor;
	}

	/** @param Sueldo[] $historial */
	public static function vigenteEn( array $historial, string $dia ): ?self {
		$elegido = null;

		foreach ( $historial as $sueldo ) {
			if ( $sueldo->rigeEn( $dia ) && ( ! $elegido || $sueldo->vigenteDesde > $elegido->vigenteDesde ) ) {
				$elegido = $sueldo;
			}
		}

		return $elegido;
	}

	/** "1.250,50" o "1250.50" → 125050 céntimos; null si no es un monto válido. */
	public static function aCentimos( string $texto ): ?int {
		$t = str_replace( array( 'S/', ' ', "\u{00A0}" ), '', trim( $texto ) );

		if ( preg_match( '/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $t ) || preg_match( '/^\d+,\d{1,2}$/', $t ) ) {
			$t = str_replace( array( '.', ',' ), array( '', '.' ), $t ); // Formato 1.250,50
		} elseif ( preg_match( '/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $t ) ) {
			$t = str_replace( ',', '', $t ); // Formato 1,250.50
		}

		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $t ) ) {
			return null;
		}

		return (int) round( (float) $t * 100 );
	}

	public static function soles( int|float $centimos ): string {
		return 'S/ ' . number_format( $centimos / 100, 2, '.', ',' );
	}
}
