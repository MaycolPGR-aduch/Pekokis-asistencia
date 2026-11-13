<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Lo que el servidor sabe de la petición: si viene del WiFi del taller
 * y si el navegador trae un pase de presencia válido (QR o código de la pantalla).
 */
final class ContextoPresencia {
	public function __construct(
		public readonly bool $enRedDelTaller,
		public readonly ?int $pantallaQr = null,
	) {
	}

	public function tieneQr(): bool {
		return null !== $this->pantallaQr;
	}
}
