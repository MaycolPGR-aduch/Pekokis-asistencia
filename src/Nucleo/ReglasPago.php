<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Reglas para calcular horas y pagos. El pago es por recibos por honorarios:
 * honorario mensual más horas extra, de sábado, de domingo y de feriado, cada
 * una con la tarifa de la persona. No hay sobretasas legales ni descuentos.
 *
 * Valores POR CONFIRMAR con el responsable: la comida descontada cuando no se
 * marca y desde cuándo se cuenta la tardanza.
 */
final class ReglasPago {
	public const TARDANZA_DESDE_ENTRADA    = 'entrada';
	public const TARDANZA_DESDE_TOLERANCIA = 'tolerancia';

	public function __construct(
		public readonly int $comidaNoMarcadaMin = 60, // Se descuenta en días laborables si no marcó la comida.
		public readonly string $tardanzaDesde = self::TARDANZA_DESDE_ENTRADA,
		// Madrugada: de 00:00 a esta hora (y hasta la entrada si llegó antes). Se paga a valor hora × multiplicador.
		public readonly int $madrugadaHastaMin = 360,
		public readonly float $multiplicadorMadrugada = 1.5,
	) {
	}

	/** @param array<string, mixed> $ajustes */
	public static function desdeAjustes( array $ajustes ): self {
		return new self(
			max( 0, min( 180, (int) ( $ajustes['comida_no_marcada_min'] ?? 60 ) ) ),
			self::TARDANZA_DESDE_TOLERANCIA === ( $ajustes['tardanza_desde'] ?? '' ) ? self::TARDANZA_DESDE_TOLERANCIA : self::TARDANZA_DESDE_ENTRADA,
			360,
			max( 1.0, min( 5.0, (float) str_replace( ',', '.', (string) ( $ajustes['madrugada_multiplicador'] ?? '1.5' ) ) ) ),
		);
	}
}
