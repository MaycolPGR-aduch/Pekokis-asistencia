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
	public const CELULAR          = 'celular';
	public const CELULAR_RESUELTO = 'celular_resuelto';
	// Desde la 0.23.1 (bloques 1 y 3 de gerencia).
	public const TARDANZA_CONVERSAR = 'tardanza_conversar';
	public const CONSTANCIA_VENCE   = 'constancia_vence';
	public const CONSTANCIA_RENOVAR = 'constancia_renovar';

	// No hay jefes de área ni RR.HH.: lo que no es para el trabajador va a gerencia.
	public const PARA_GERENCIA   = 'gerencia';
	public const PARA_TRABAJADOR = 'trabajador';
	/** @deprecated Desde la 0.23.1 es gerencia. */
	public const PARA_JEFE = self::PARA_GERENCIA;
	/** @deprecated Desde la 0.23.1 es gerencia. */
	public const PARA_ADMIN = self::PARA_GERENCIA;

	/** tipo => [etiqueta para Ajustes, a quién, título del correo (uno), título (varios)]. */
	public const TIPOS = array(
		self::AUSENTE          => array( 'No llegó (pasada la tolerancia)', self::PARA_GERENCIA, 'No llegó a su hora', 'No llegaron a su hora' ),
		self::REFRIGERIO_LARGO => array( 'Refrigerio que se pasa del tiempo', self::PARA_GERENCIA, 'Refrigerio que se alarga', 'Refrigerios que se alargan' ),
		self::SALIDA_LARGA     => array( 'Salida temporal que se alarga', self::PARA_GERENCIA, 'Salida que se alarga', 'Salidas que se alargan' ),
		self::REVISION         => array( 'Marca por revisar (emergencia, permiso, salud)', self::PARA_GERENCIA, 'Marca por revisar', 'Marcas por revisar' ),
		self::SOLICITUD        => array( 'Solicitud nueva de "olvidé marcar"', self::PARA_GERENCIA, 'Solicitud por aprobar', 'Solicitudes por aprobar' ),
		self::JORNADA_LARGA    => array( 'Jornada muy larga (pasó el límite de horas)', self::PARA_GERENCIA, 'Jornada muy larga por revisar', 'Jornadas muy largas por revisar' ),
		self::TARDANZA_CONVERSAR => array( 'Llegó a la 4.ª tardanza o más (debe conversar con gerencia)', self::PARA_GERENCIA, 'Tardanza para conversar', 'Tardanzas para conversar' ),
		self::SIN_SALIDA       => array( 'Sigue sin marcar la salida a la hora límite', self::PARA_TRABAJADOR, 'No marcaste tu salida', 'No marcaste tu salida' ),
		self::RESUELTA         => array( 'Su solicitud fue aprobada o rechazada', self::PARA_TRABAJADOR, 'Tu solicitud fue revisada', 'Tus solicitudes fueron revisadas' ),
		self::PANTALLA         => array( 'Pantalla QR sin conexión', self::PARA_GERENCIA, 'Pantalla QR sin conexión', 'Pantallas QR sin conexión' ),
		self::CELULAR          => array( 'Registro, cambio o liberación de celular por aprobar', self::PARA_GERENCIA, 'Celular por aprobar', 'Celulares por aprobar' ),
		self::CELULAR_RESUELTO => array( 'Su registro o cambio de celular fue aprobado o rechazado', self::PARA_TRABAJADOR, 'Tu celular fue revisado', 'Tus celulares fueron revisados' ),
		self::RED_NUEVA        => array( 'Posible IP nueva del taller', self::PARA_GERENCIA, 'Posible IP nueva del taller', 'Posibles IP nuevas del taller' ),
		self::RECIBO_ENVIADO   => array( 'Llegó un recibo por honorarios o una constancia de retención', self::PARA_GERENCIA, 'Recibo o constancia nueva', 'Recibos o constancias nuevas' ),
		self::CONSTANCIA_VENCE => array( 'Constancia de retención por vencer (30 días) o vencida', self::PARA_GERENCIA, 'Constancia de retención por vencer', 'Constancias de retención por vencer' ),
		self::RECIBO_OBSERVADO => array( 'Su recibo o su constancia fue observado', self::PARA_TRABAJADOR, 'Tu recibo o tu constancia tiene una observación', 'Tus recibos tienen observaciones' ),
		self::RECIBO_PENDIENTE => array( 'Recordatorio de recibo pendiente (botón "Recordar")', self::PARA_TRABAJADOR, 'Falta tu recibo por honorarios', 'Faltan tus recibos por honorarios' ),
		self::CONSTANCIA_RENOVAR => array( 'Su constancia de retención está por vencer o venció', self::PARA_TRABAJADOR, 'Tu constancia de retención vence pronto', 'Tu constancia de retención vence pronto' ),
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
		return self::TIPOS[ $tipo ][1] ?? self::PARA_GERENCIA;
	}

	public static function titulo( string $tipo, int $cuantos = 1 ): string {
		return self::TIPOS[ $tipo ][ $cuantos > 1 ? 3 : 2 ] ?? 'Aviso de asistencia';
	}
}
