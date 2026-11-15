<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Qué avisos tocan según la hora y el estado del día de un trabajador (fase 4).
 *
 * Solo decide; no envía nada. Cada aviso trae una clave única para no repetirlo:
 * por día (ausente, sin salida) o por la marca que lo originó (refrigerio o
 * salida temporal que se alarga: si vuelve a salir, es otro aviso).
 */
final class ReglasAvisos {
	// Los decide el reloj (los revisa el cron cada 5 minutos).
	public const AUSENTE          = 'ausente';
	public const REFRIGERIO_LARGO = 'refrigerio_largo';
	public const SALIDA_LARGA     = 'salida_larga';
	public const SIN_SALIDA       = 'sin_salida';
	// Los dispara algo que pasó.
	public const SOLICITUD        = 'solicitud';
	public const REVISION         = 'revision';
	public const RESUELTA         = 'resuelta';
	public const PANTALLA         = 'pantalla';
	public const RED_NUEVA        = 'red_nueva';
	public const RECIBO_ENVIADO   = 'recibo_enviado';
	public const RECIBO_OBSERVADO = 'recibo_observado';
	public const RECIBO_PENDIENTE = 'recibo_pendiente';
	public const JORNADA_LARGA    = 'jornada_larga';

	public const PARA_JEFE       = 'jefe';
	public const PARA_TRABAJADOR = 'trabajador';
	public const PARA_ADMIN      = 'admin';

	/** tipo => [etiqueta para Ajustes, a quién, título del correo (uno), título (varios)]. */
	public const TIPOS = array(
		self::AUSENTE          => array( 'No llegó (pasada la tolerancia)', self::PARA_JEFE, 'No llegó a su hora', 'No llegaron a su hora' ),
		self::REFRIGERIO_LARGO => array( 'Refrigerio que se pasa del tiempo', self::PARA_JEFE, 'Refrigerio que se alarga', 'Refrigerios que se alargan' ),
		self::SALIDA_LARGA     => array( 'Salida temporal que se alarga', self::PARA_JEFE, 'Salida que se alarga', 'Salidas que se alargan' ),
		self::REVISION         => array( 'Marca por revisar (emergencia, permiso, salud)', self::PARA_JEFE, 'Marca por revisar', 'Marcas por revisar' ),
		self::SOLICITUD        => array( 'Solicitud nueva de "olvidé marcar"', self::PARA_JEFE, 'Solicitud por aprobar', 'Solicitudes por aprobar' ),
		self::JORNADA_LARGA    => array( 'Jornada muy larga (pasó el límite de horas)', self::PARA_JEFE, 'Jornada muy larga por revisar', 'Jornadas muy largas por revisar' ),
		self::SIN_SALIDA       => array( 'Sigue sin marcar la salida a la hora límite', self::PARA_TRABAJADOR, 'No marcaste tu salida', 'No marcaste tu salida' ),
		self::RESUELTA         => array( 'Su solicitud fue aprobada o rechazada', self::PARA_TRABAJADOR, 'Tu solicitud fue revisada', 'Tus solicitudes fueron revisadas' ),
		self::PANTALLA         => array( 'Pantalla QR sin conexión', self::PARA_ADMIN, 'Pantalla QR sin conexión', 'Pantallas QR sin conexión' ),
		self::RED_NUEVA        => array( 'Posible IP nueva del taller', self::PARA_ADMIN, 'Posible IP nueva del taller', 'Posibles IP nuevas del taller' ),
		self::RECIBO_ENVIADO   => array( 'Llegó un recibo por honorarios', self::PARA_ADMIN, 'Recibo por honorarios nuevo', 'Recibos por honorarios nuevos' ),
		self::RECIBO_OBSERVADO => array( 'Su recibo por honorarios fue observado', self::PARA_TRABAJADOR, 'Tu recibo tiene una observación', 'Tus recibos tienen observaciones' ),
		self::RECIBO_PENDIENTE => array( 'Recordatorio de recibo pendiente (botón "Recordar")', self::PARA_TRABAJADOR, 'Falta tu recibo por honorarios', 'Faltan tus recibos por honorarios' ),
	);

	public function __construct(
		public readonly string $horaSinSalida = '20:00',
		public readonly int $margenAusenciaMin = 10,
		public readonly int $margenRefrigerioMin = 10,
		public readonly int $limiteSalidaMin = 120,
	) {
	}

	/** @param array<string, mixed> $ajustes */
	public static function desdeAjustes( array $ajustes ): self {
		$hora = (string) ( $ajustes['aviso_hora_sin_salida'] ?? '20:00' );

		return new self(
			preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora ) ? $hora : '20:00',
			max( 0, min( 240, (int) ( $ajustes['aviso_margen_ausencia'] ?? 10 ) ) ),
			max( 0, min( 240, (int) ( $ajustes['aviso_margen_refrigerio'] ?? 10 ) ) ),
			max( 15, min( 600, (int) ( $ajustes['aviso_limite_salida'] ?? 120 ) ) ),
		);
	}

	/**
	 * Avisos que tocan ahora para un trabajador.
	 *
	 * @return array<string, string> tipo => clave única
	 */
	public function evaluar( ResumenDia $r, HorarioDelDia $h, string $dia, DateTimeZone $zona, DateTimeImmutable $ahora ): array {
		$avisos = array();

		if ( ResumenDia::AUSENTE === $r->situacion && $h->entrada ) {
			$limite = ( new DateTimeImmutable( $dia . ' ' . substr( $h->entrada, 0, 5 ), $zona ) )->modify( '+' . ( $h->toleranciaMin + $this->margenAusenciaMin ) . ' minutes' );

			if ( $ahora >= $limite ) {
				$avisos[ self::AUSENTE ] = self::AUSENTE . '|' . $dia;
			}
		}

		$desde = $r->desde ? (string) $r->desde->getTimestamp() : '';

		if ( Estado::REFRIGERIO === $r->estado && $r->minutosEnEstado > $h->refrigerioMin + $this->margenRefrigerioMin ) {
			$avisos[ self::REFRIGERIO_LARGO ] = self::REFRIGERIO_LARGO . '|' . $desde;
		}

		if ( Estado::FUERA_TEMPORAL === $r->estado && $r->minutosEnEstado > $this->limiteSalidaMin ) {
			$avisos[ self::SALIDA_LARGA ] = self::SALIDA_LARGA . '|' . $desde;
		}

		if ( Estado::FUERA !== $r->estado && $ahora >= $this->limiteSinSalida( $h, $dia, $zona ) ) {
			$avisos[ self::SIN_SALIDA ] = self::SIN_SALIDA . '|' . $dia;
		}

		return $avisos;
	}

	/** La hora límite, o 30 minutos después del fin del horario si este termina más tarde. */
	public function limiteSinSalida( HorarioDelDia $h, string $dia, DateTimeZone $zona ): DateTimeImmutable {
		$limite = new DateTimeImmutable( $dia . ' ' . $this->horaSinSalida, $zona );

		if ( $h->laborable && $h->salida ) {
			$fin = ( new DateTimeImmutable( $dia . ' ' . substr( $h->salida, 0, 5 ), $zona ) )->modify( '+30 minutes' );

			if ( $h->entrada && substr( $h->salida, 0, 5 ) <= substr( $h->entrada, 0, 5 ) ) {
				$fin = $fin->modify( '+1 day' ); // Turno que termina pasada la medianoche.
			}

			$limite = max( $limite, $fin );
		}

		return $limite;
	}

	public static function etiqueta( string $tipo ): string {
		return self::TIPOS[ $tipo ][0] ?? $tipo;
	}

	public static function destinatario( string $tipo ): string {
		return self::TIPOS[ $tipo ][1] ?? self::PARA_ADMIN;
	}

	public static function titulo( string $tipo, int $cuantos = 1 ): string {
		return self::TIPOS[ $tipo ][ $cuantos > 1 ? 3 : 2 ] ?? 'Aviso de asistencia';
	}
}
