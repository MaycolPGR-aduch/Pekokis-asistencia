<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Constancia de retención (pedido de gerencia, 0.23.0): el documento de
 * 4.ª categoría (suspensión de retenciones, formulario 1609 de SUNAT) o de
 * 5.ª categoría que sube el trabajador desde la app. Gerencia lo revisa como
 * los recibos (recibido u observado) y el sistema avisa antes de que venza.
 * Usa los mismos estados que los recibos.
 */
final class ValidacionConstancia {
	public const TIPOS = array(
		'cuarta' => 'Suspensión de retenciones de 4.ª categoría',
		'quinta' => 'Retención de 5.ª categoría',
	);

	/** Días antes del vencimiento en que se empieza a avisar. */
	public const AVISO_DIAS = 30;

	public const VIGENTE    = 'vigente';
	public const POR_VENCER = 'por_vencer';
	public const VENCIDA    = 'vencida';

	/**
	 * @param array<string, mixed> $datos tipo, numero, vigente_hasta.
	 * @return array{tipo: string, numero: string, vigente_hasta: string}
	 */
	public static function normalizar( array $datos ): array {
		return array(
			'tipo'          => trim( (string) ( $datos['tipo'] ?? '' ) ),
			'numero'        => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $datos['numero'] ?? '' ) ) ), 0, 40 ),
			'vigente_hasta' => trim( (string) ( $datos['vigente_hasta'] ?? '' ) ),
		);
	}

	/**
	 * @param array{tipo: string, numero: string, vigente_hasta: string} $c
	 * @return array<string, string>
	 */
	public static function errores( array $c, string $hoy ): array {
		$errores = array();

		if ( ! isset( self::TIPOS[ $c['tipo'] ] ) ) {
			$errores['tipo'] = 'Elige qué constancia es: de 4.ª o de 5.ª categoría.';
		}

		if ( ! ValidacionTrabajador::esFecha( $c['vigente_hasta'] ) ) {
			$errores['vigente_hasta'] = 'Escribe hasta qué fecha vale (sale en la constancia).';
		} elseif ( $c['vigente_hasta'] < $hoy ) {
			$errores['vigente_hasta'] = 'Esa constancia ya venció. Sube la del año en curso.';
		} elseif ( $c['vigente_hasta'] > ( new DateTimeImmutable( $hoy ) )->modify( '+2 years' )->format( 'Y-m-d' ) ) {
			$errores['vigente_hasta'] = 'La fecha parece muy lejana. Revísala.';
		}

		return $errores;
	}

	/** Hasta el 31 de diciembre del año: lo normal para la constancia de SUNAT. */
	public static function vigenciaPorDefecto( string $hoy ): string {
		return substr( $hoy, 0, 4 ) . '-12-31';
	}

	public static function diasParaVencer( string $vigenteHasta, string $hoy ): int {
		return (int) ( new DateTimeImmutable( $hoy ) )->diff( new DateTimeImmutable( $vigenteHasta ) )->format( '%r%a' );
	}

	/** Vigente, por vencer (30 días o menos) o vencida. */
	public static function vigencia( string $vigenteHasta, string $hoy ): string {
		$dias = self::diasParaVencer( $vigenteHasta, $hoy );

		return match ( true ) {
			$dias < 0                 => self::VENCIDA,
			$dias <= self::AVISO_DIAS => self::POR_VENCER,
			default                   => self::VIGENTE,
		};
	}

	/** "Vence el 31/12/2026", "Vence en 12 días", "Vence hoy" o "Venció el 31/12/2025". */
	public static function textoVigencia( string $vigenteHasta, string $hoy ): string {
		$dias  = self::diasParaVencer( $vigenteHasta, $hoy );
		$fecha = ( new DateTimeImmutable( $vigenteHasta ) )->format( 'd/m/Y' );

		return match ( true ) {
			$dias < 0                 => 'Venció el ' . $fecha,
			0 === $dias               => 'Vence hoy',
			$dias <= self::AVISO_DIAS => 'Vence en ' . $dias . ( 1 === $dias ? ' día' : ' días' ) . ' (' . $fecha . ')',
			default                   => 'Vale hasta el ' . $fecha,
		};
	}

	/** Se puede subir una nueva si la última no está esperando revisión. */
	public static function puedeEnviar( ?string $estadoActual ): bool {
		return ValidacionRecibo::ENVIADO !== $estadoActual;
	}
}
