<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Recordatorio de campaña dentro de la app (maquetas T9A y G15): "Aún no marcas
 * tu entrada". Sale solo si gerencia lo activó, dentro de las fechas de la
 * campaña, en un día laborable de la persona, pasada la hora y si todavía no
 * marcó. Desaparece al marcar.
 */
final class RecordatorioCampana {
	public const TEXTO = 'Aún no marcas tu entrada. Recuerda marcar al llegar al taller. Escanea el QR de la pantalla.';

	public function __construct(
		public readonly bool $activo,
		public readonly string $desde,
		public readonly string $hasta,
		public readonly string $hora,
		public readonly string $texto = self::TEXTO,
	) {
	}

	/** @param array<string, mixed> $ajustes */
	public static function desdeAjustes( array $ajustes ): self {
		$hora = (string) ( $ajustes['campana_hora'] ?? '09:15' );

		return new self(
			! empty( $ajustes['campana_activa'] ),
			self::fecha( (string) ( $ajustes['campana_desde'] ?? '' ) ),
			self::fecha( (string) ( $ajustes['campana_hasta'] ?? '' ) ),
			preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora ) ? $hora : '09:15',
			'' !== trim( (string) ( $ajustes['campana_texto'] ?? '' ) ) ? trim( (string) $ajustes['campana_texto'] ) : self::TEXTO,
		);
	}

	/** ¿Está dentro de las fechas de campaña ese día? */
	public function enCampana( string $dia ): bool {
		return $this->activo && '' !== $this->desde && '' !== $this->hasta && $dia >= $this->desde && $dia <= $this->hasta;
	}

	/**
	 * @param string $horaAhora "HH:MM" de Lima.
	 */
	public function toca( string $dia, string $horaAhora, bool $laborable, bool $yaMarco ): bool {
		return $this->enCampana( $dia ) && $laborable && ! $yaMarco && $horaAhora >= $this->hora;
	}

	/** Problemas para guardar los ajustes (vacío si está bien). */
	public function errores(): array {
		$errores = array();

		if ( '' === $this->desde || '' === $this->hasta ) {
			$errores[] = 'Elige la fecha de inicio y de fin de la campaña.';
		} elseif ( $this->hasta < $this->desde ) {
			$errores[] = 'La campaña termina antes de empezar.';
		}

		if ( mb_strlen( $this->texto ) > 200 ) {
			$errores[] = 'El texto del aviso es muy largo (máximo 200 letras).';
		}

		return $errores;
	}

	private static function fecha( string $texto ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $texto ) && checkdate( (int) substr( $texto, 5, 2 ), (int) substr( $texto, 8, 2 ), (int) substr( $texto, 0, 4 ) ) ? $texto : '';
	}
}
