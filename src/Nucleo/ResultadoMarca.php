<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Decisión del validador: aceptar la marca, devolver la que ya existe (doble toque) o rechazarla.
 */
final class ResultadoMarca {
	public const ACEPTADA  = 'aceptada';
	public const REBOTE    = 'rebote';
	public const RECHAZADA = 'rechazada';

	private function __construct(
		public readonly string $resultado,
		public readonly ?PruebaPresencia $prueba = null,
		public readonly bool $requiereRevision = false,
		public readonly ?Marca $marcaExistente = null,
		public readonly string $codigo = '',
		public readonly string $mensaje = '',
	) {
	}

	public static function aceptada( PruebaPresencia $prueba, bool $requiereRevision ): self {
		return new self( self::ACEPTADA, $prueba, $requiereRevision );
	}

	public static function rebote( Marca $marcaExistente ): self {
		return new self( self::REBOTE, null, false, $marcaExistente );
	}

	public static function rechazada( string $codigo, string $mensaje ): self {
		return new self( self::RECHAZADA, null, false, null, $codigo, $mensaje );
	}

	public function esAceptada(): bool {
		return self::ACEPTADA === $this->resultado;
	}

	public function esRebote(): bool {
		return self::REBOTE === $this->resultado;
	}

	public function esRechazada(): bool {
		return self::RECHAZADA === $this->resultado;
	}
}
