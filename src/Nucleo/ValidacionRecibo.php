<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Recibo por honorarios que envía el trabajador cada mes: número (serie-número,
 * por ejemplo E001-482), monto y PDF. Gerencia lo compara con el cálculo del mes.
 */
final class ValidacionRecibo {
	public const ENVIADO   = 'enviado';
	public const RECIBIDO  = 'recibido';
	public const OBSERVADO = 'observado';
	public const ANULADO   = 'anulado';

	public const ESTADOS = array(
		self::ENVIADO   => 'Enviado, esperando revisión',
		self::RECIBIDO  => 'Recibido',
		self::OBSERVADO => 'Observado',
		self::ANULADO   => 'Envío anulado',
	);

	/** 5 MB: un recibo electrónico de SUNAT pesa unos 50 KB. */
	public const MAX_BYTES = 5 * 1024 * 1024;

	/** Diferencia (céntimos) que todavía cuenta como "coincide", por redondeos. */
	private const TOLERANCIA = 5;

	/**
	 * @param array<string, mixed> $datos
	 * @return array{numero: string, monto: ?int}
	 */
	public static function normalizar( array $datos ): array {
		$numero = strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $datos['numero'] ?? '' ) ) );

		return array(
			'numero' => $numero,
			'monto'  => Sueldo::aCentimos( (string) ( $datos['monto'] ?? '' ) ),
		);
	}

	/**
	 * @param array{numero: string, monto: ?int} $r
	 * @return array<string, string>
	 */
	public static function errores( array $r ): array {
		$errores = array();

		if ( ! preg_match( '/^[A-Z][A-Z0-9]{3}-\d{1,8}$/', $r['numero'] ) ) {
			$errores['numero'] = 'Escribe el número como aparece en el recibo: serie y número, por ejemplo E001-482.';
		}

		if ( null === $r['monto'] || $r['monto'] <= 0 ) {
			$errores['monto'] = 'Escribe el monto del recibo, mayor que cero (por ejemplo 1934.00).';
		} elseif ( $r['monto'] > 10000000 ) {
			$errores['monto'] = 'El monto parece demasiado alto. Revísalo.';
		}

		return $errores;
	}

	/** Mensaje si el archivo no sirve (vacío, muy grande o no es PDF); null si está bien. */
	public static function problemaArchivo( int $bytes, string $cabecera ): ?string {
		if ( $bytes <= 0 ) {
			return 'El archivo está vacío. Vuelve a descargar el PDF del recibo y elígelo otra vez.';
		}

		if ( $bytes > self::MAX_BYTES ) {
			return 'El archivo pesa más de 5 MB. Sube el PDF original del recibo.';
		}

		if ( ! str_starts_with( $cabecera, '%PDF-' ) ) {
			return 'El archivo no es un PDF. Sube el PDF del recibo por honorarios.';
		}

		return null;
	}

	/**
	 * Compara el monto del recibo con el total calculado del mes.
	 *
	 * @return array{coincide: bool, diferencia: int} diferencia = recibo − cálculo, en céntimos.
	 */
	public static function comparar( int $montoRecibo, int $totalCalculado ): array {
		$diferencia = $montoRecibo - $totalCalculado;

		return array( 'coincide' => abs( $diferencia ) <= self::TOLERANCIA, 'diferencia' => $diferencia );
	}

	/** "Coincide" o "Difiere S/ 12.00 (más)". */
	public static function textoComparacion( int $montoRecibo, int $totalCalculado ): string {
		$c = self::comparar( $montoRecibo, $totalCalculado );

		return $c['coincide'] ? 'Coincide' : 'Difiere ' . Sueldo::soles( abs( $c['diferencia'] ) ) . ( $c['diferencia'] > 0 ? ' (más)' : ' (menos)' );
	}

	/** Se puede enviar un recibo nuevo si el último del mes no está enviado ni recibido. */
	public static function puedeEnviar( ?string $estadoActual ): bool {
		return ! in_array( $estadoActual, array( self::ENVIADO, self::RECIBIDO ), true );
	}

	/** Solo se anula mientras gerencia no lo revisó. */
	public static function puedeAnular( ?string $estadoActual ): bool {
		return self::ENVIADO === $estadoActual;
	}
}
