<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Honorario y tarifas de una persona, con su vigencia (pago por recibos por
 * honorarios). Las horas normales están incluidas en el honorario; cada tipo de
 * hora adicional tiene su propia tarifa. Montos en céntimos para no arrastrar
 * errores de redondeo. Cada cambio crea un registro nuevo; el historial no se edita.
 *
 * Desde la 0.22.0 (pedido de gerencia): el sábado ya no tiene tarifa propia (se
 * paga como hora extra si completó la semana, o a valor hora si no); el valor
 * hora es el honorario ÷ 192; y hay un modo opcional "por horas", en el que el
 * pago son las horas trabajadas × valor hora (con extra, madrugada y domingo o
 * feriado aparte, si se eligen).
 */
final class Sueldo {
	public const TARIFAS = array(
		'extra'   => 'Hora extra (y sábado con semana completa)',
		'domingo' => 'Hora de domingo',
		'feriado' => 'Hora de feriado',
	);

	/** Base para el valor hora: honorario ÷ 192 horas al mes. */
	public const HORAS_MES = 192;

	public const FIJO  = 'fijo';
	public const HORAS = 'horas';

	/** Lo que, en el modo por horas, se puede pagar con su propia tarifa. */
	public const APARTE = array(
		'extra'     => 'Horas extra y sábado con su tarifa',
		'madrugada' => 'Madrugada con el multiplicador',
		'domingo'   => 'Domingo y feriado con su tarifa',
	);

	public function __construct(
		public readonly int $honorarioCentimos,
		public readonly int $tarifaExtra,   // Céntimos por hora.
		public readonly int $tarifaSabado,
		public readonly int $tarifaDomingo,
		public readonly int $tarifaFeriado,
		public readonly string $vigenteDesde,        // Y-m-d
		public readonly ?string $vigenteHasta = null, // Y-m-d, null = sigue vigente.
		public readonly ?int $id = null,
		public readonly string $modo = self::FIJO,
		/** @var string[] Claves de APARTE, solo en el modo por horas. */
		public readonly array $aparte = array(),
	) {
	}

	/** Valor de la hora normal en céntimos: honorario ÷ 192 (informativo en el sueldo fijo). */
	public function valorHora(): int {
		return (int) round( $this->honorarioCentimos / self::HORAS_MES );
	}

	public function porHoras(): bool {
		return self::HORAS === $this->modo;
	}

	/** En el modo por horas, ¿ese tipo se paga con su tarifa? (en el fijo, siempre). */
	public function aparte( string $tipo ): bool {
		return ! $this->porHoras() || in_array( $tipo, $this->aparte, true );
	}

	public function rigeEn( string $dia ): bool {
		return $this->vigenteDesde <= $dia && ( null === $this->vigenteHasta || $dia <= $this->vigenteHasta );
	}

	/** Tarifa por hora (céntimos) de un tipo: extra, sabado, domingo o feriado. */
	public function tarifa( string $tipo ): int {
		return match ( $tipo ) {
			'extra'   => $this->tarifaExtra,
			'sabado'  => $this->tarifaSabado,
			'domingo' => $this->tarifaDomingo,
			'feriado' => $this->tarifaFeriado,
			default   => 0,
		};
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
