<?php

namespace Pekokis\Asistencia\Nucleo;

use InvalidArgumentException;

/**
 * Pase firmado que el servidor deja en el navegador al validar el QR o el código
 * de la pantalla. Dura unos minutos, para que el trabajador alcance a elegir el
 * motivo y poner la huella después de escanear.
 */
final class PasePresencia {
	public function __construct(
		private readonly string $secreto,
		private readonly int $segundos = 180,
	) {
		if ( strlen( $secreto ) < 32 ) {
			throw new InvalidArgumentException( 'El secreto del pase debe tener al menos 32 caracteres.' );
		}
	}

	public function emitir( int $pantalla, int $ahora ): string {
		$vence = $ahora + $this->segundos;

		return $pantalla . '.' . $vence . '.' . $this->firma( $pantalla, $vence );
	}

	/** @return int|null Pantalla del pase, o null si no es válido o ya venció. */
	public function validar( string $pase, int $ahora ): ?int {
		if ( ! preg_match( '/^(\d{1,10})\.(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $pase, $partes ) ) {
			return null;
		}

		$pantalla = (int) $partes[1];
		$vence    = (int) $partes[2];

		if ( $vence < $ahora ) {
			return null;
		}

		return hash_equals( $this->firma( $pantalla, $vence ), $partes[3] ) ? $pantalla : null;
	}

	/** Segundos que le quedan a un pase válido (0 si no vale). */
	public function segundosRestantes( string $pase, int $ahora ): int {
		if ( null === $this->validar( $pase, $ahora ) ) {
			return 0;
		}

		return max( 0, (int) explode( '.', $pase )[1] - $ahora );
	}

	private function firma( int $pantalla, int $vence ): string {
		return Base64Url::codificar( hash_hmac( 'sha256', 'pase|' . $pantalla . '|' . $vence, $this->secreto, true ) );
	}
}
