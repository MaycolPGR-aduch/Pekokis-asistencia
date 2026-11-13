<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Decide si una marca se acepta (plan técnico, sección 6.3).
 *
 * No toca la base de datos: la capa de WordPress ya comprobó el ticket de marca,
 * la passkey y que el trabajador esté activo, y le pasa aquí las marcas del día
 * y el contexto de presencia. Si la respuesta es "aceptada", la guarda con la
 * prueba indicada; si es "rebote", devuelve la marca existente sin duplicar.
 */
final class ValidadorMarca {
	public function __construct(
		private readonly ModoPresencia $modo,
		private readonly int $segundosAntirrebote = 120,
	) {
	}

	/**
	 * @param Marca[] $marcasDelDia Marcas efectivas del día laboral del trabajador.
	 */
	public function decidir(
		array $marcasDelDia,
		SolicitudMarca $solicitud,
		ContextoPresencia $contexto,
		DateTimeImmutable $ahora
	): ResultadoMarca {
		$marcas = MaquinaEstados::ordenar( $marcasDelDia );
		$ultima = $marcas ? $marcas[ count( $marcas ) - 1 ] : null;

		// 1. Doble toque: se devuelve la marca ya guardada, sin duplicar.
		if ( $ultima && $this->esRebote( $ultima, $solicitud, $ahora ) ) {
			return ResultadoMarca::rebote( $ultima );
		}

		// 2. Motivo: obligatorio en las salidas, prohibido en las entradas.
		$motivo = $solicitud->motivo;

		if ( TipoMarca::SALIDA === $solicitud->tipo ) {
			if ( null === $motivo ) {
				return ResultadoMarca::rechazada( 'motivo_requerido', 'Elige el motivo de la salida.' );
			}

			if ( ! $motivo->activo ) {
				return ResultadoMarca::rechazada( 'motivo_inactivo', 'Ese motivo ya no está disponible.' );
			}
		} elseif ( null !== $motivo ) {
			return ResultadoMarca::rechazada( 'motivo_no_aplica', 'La entrada no lleva motivo.' );
		}

		// 3. Estado: la acción tiene que corresponder al estado actual.
		$estado = MaquinaEstados::estadoActual( $marcas );

		if ( ! MaquinaEstados::permite( $estado, $solicitud->tipo ) ) {
			return ResultadoMarca::rechazada( 'accion_invalida', 'Esa marca no corresponde: ' . $estado->frase() . '.' );
		}

		// 4. Presencia en el taller según el modo configurado.
		$prueba = $this->modo->evaluar( $contexto );

		if ( null === $prueba ) {
			if ( null === $motivo || ! $motivo->sinPresencia ) {
				return ResultadoMarca::rechazada( 'sin_presencia', $this->modo->mensajeSinPresencia( $contexto ) );
			}

			$prueba = PruebaPresencia::SIN_PRESENCIA;
		}

		$requiereRevision = PruebaPresencia::SIN_PRESENCIA === $prueba
			|| ( null !== $motivo && $motivo->requiereAprobacion );

		return ResultadoMarca::aceptada( $prueba, $requiereRevision );
	}

	private function esRebote( Marca $ultima, SolicitudMarca $solicitud, DateTimeImmutable $ahora ): bool {
		if ( $ultima->tipo !== $solicitud->tipo || $ultima->motivo?->clave !== $solicitud->motivo?->clave ) {
			return false;
		}

		$segundos = $ahora->getTimestamp() - $ultima->marcadoUtc->getTimestamp();

		return $segundos >= 0 && $segundos < $this->segundosAntirrebote;
	}
}
