<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Lo que muestra la pantalla de marcar (T2 de las maquetas): los cuatro pasos de
 * "Tu día", el botón principal, las otras salidas posibles y cuánto lleva.
 *
 * Los pasos son entrada, inicio de comida, fin de comida y salida. Las salidas
 * temporales (encargo, salud…) siguen existiendo, pero van como acción
 * secundaria: el botón principal sigue el camino de las maquetas.
 */
final class VistaDia {
	public const PASOS = array(
		'entrada'       => 'Entrada',
		'inicio_comida' => 'Inicio de comida',
		'fin_comida'    => 'Fin de comida',
		'salida'        => 'Salida',
	);

	/** Texto del botón principal según la acción. */
	public const BOTONES = array(
		'entrada'           => 'Marcar entrada',
		'salida_refrigerio' => 'Salir a comer',
		'regreso_comida'    => 'Volver de comer',
		'regreso'           => 'Volver al taller',
		'fin_jornada'       => 'Marcar salida',
		'salida_temporal'   => 'Otra salida (encargo, salud…)',
	);

	/**
	 * @param Marca[] $marcasDelDia
	 * @return array{
	 *   estado: string, pasos: array<int, array{clave: string, nombre: string, hora: ?string, estado: string}>,
	 *   siguiente: ?array{accion: string, etiqueta: string}, otras: array<int, array{accion: string, etiqueta: string}>,
	 *   trabajado: int, esperados: int, faltan: int, terminado: bool,
	 *   comida: ?array{desde: string, minutos: int}, fuera: ?array{desde: string, minutos: int, motivo: string}
	 * }
	 */
	public static function de( array $marcasDelDia, HorarioDelDia $horario, string $dia, DateTimeZone $zona, DateTimeImmutable $ahora ): array {
		$marcas = MaquinaEstados::ordenar( $marcasDelDia );
		$estado = MaquinaEstados::estadoActual( $marcas );
		$ultima = $marcas ? $marcas[ count( $marcas ) - 1 ] : null;
		$hora   = static fn( Marca $m ): string => $m->marcadoUtc->setTimezone( $zona )->format( 'H:i' );

		// Los cuatro pasos.
		$horas    = array_fill_keys( array_keys( self::PASOS ), null );
		$enComida = false;

		foreach ( $marcas as $m ) {
			if ( TipoMarca::ENTRADA === $m->tipo ) {
				if ( null === $horas['entrada'] ) {
					$horas['entrada'] = $hora( $m );
				} elseif ( $enComida && null === $horas['fin_comida'] ) {
					$horas['fin_comida'] = $hora( $m );
				}

				$enComida = false;
				continue;
			}

			$categoria = $m->motivo?->categoria;

			if ( CategoriaMotivo::REFRIGERIO === $categoria && null === $horas['inicio_comida'] ) {
				$horas['inicio_comida'] = $hora( $m );
				$enComida               = true;
			} elseif ( CategoriaMotivo::FIN_JORNADA === $categoria ) {
				$horas['salida'] = $hora( $m );
			}
		}

		// Si volvió a entrar después de "terminar", la salida anterior ya no es la del día.
		if ( Estado::FUERA !== $estado ) {
			$horas['salida'] = null;
		}

		$terminado = Estado::FUERA === $estado && null !== $horas['salida'];
		$comidaHecha = null !== $horas['fin_comida'];

		// Botón principal y otras acciones.
		$siguiente = null;
		$otras     = array();

		switch ( $estado ) {
			case Estado::FUERA:
				$siguiente = $terminado ? null : self::accion( 'entrada' );
				if ( $terminado ) {
					$otras[] = array( 'accion' => 'entrada', 'etiqueta' => 'Volví a trabajar: marcar entrada' );
				}
				break;
			case Estado::REFRIGERIO:
				$siguiente = array( 'accion' => 'regreso', 'etiqueta' => self::BOTONES['regreso_comida'] );
				break;
			case Estado::FUERA_TEMPORAL:
				$siguiente = self::accion( 'regreso' );
				break;
			case Estado::TRABAJANDO:
				if ( null === $horas['inicio_comida'] ) {
					$siguiente = self::accion( 'salida_refrigerio' );
					$otras[]   = self::accion( 'fin_jornada' );
				} else {
					$siguiente = self::accion( 'fin_jornada' );
				}
				$otras[] = self::accion( 'salida_temporal' );
				break;
		}

		// Qué paso va "Siguiente".
		$pasoSiguiente = match ( $siguiente['accion'] ?? '' ) {
			'entrada'           => null === $horas['entrada'] ? 'entrada' : null,
			'salida_refrigerio' => 'inicio_comida',
			'regreso'           => Estado::REFRIGERIO === $estado ? 'fin_comida' : null,
			'fin_jornada'       => 'salida',
			default             => null,
		};

		$pasos = array();

		foreach ( self::PASOS as $clave => $nombre ) {
			$pasos[] = array(
				'clave'  => $clave,
				'nombre' => $nombre,
				'hora'   => $horas[ $clave ],
				'estado' => null !== $horas[ $clave ] ? 'hecho' : ( $clave === $pasoSiguiente ? 'sig' : ( $terminado ? 'omitido' : 'pend' ) ),
			);
		}

		// Cuánto lleva: los tramos cerrados más el abierto, si está trabajando.
		$trabajado = TotalesDia::calcular( $marcas )->minutosDentro;

		if ( Estado::TRABAJANDO === $estado && $ultima ) {
			$trabajado += max( 0, intdiv( $ahora->getTimestamp() - $ultima->marcadoUtc->getTimestamp(), 60 ) );
		}

		$esperados = 0;

		if ( $horario->laborable && $horario->entrada && $horario->salida ) {
			$inicio    = new DateTimeImmutable( $dia . ' ' . substr( $horario->entrada, 0, 5 ), $zona );
			$fin       = new DateTimeImmutable( $dia . ' ' . substr( $horario->salida, 0, 5 ), $zona );
			$esperados = max( 0, intdiv( $fin->getTimestamp() - $inicio->getTimestamp(), 60 ) - $horario->refrigerioMin );
		}

		$desde   = $ultima ? $hora( $ultima ) : '';
		$minutos = $ultima ? max( 0, intdiv( $ahora->getTimestamp() - $ultima->marcadoUtc->getTimestamp(), 60 ) ) : 0;

		return array(
			'estado'    => $estado->value,
			'pasos'     => $pasos,
			'siguiente' => $siguiente,
			'otras'     => $otras,
			'trabajado' => $trabajado,
			'esperados' => $esperados,
			'faltan'    => max( 0, $esperados - $trabajado ),
			'terminado' => $terminado,
			'comida'    => Estado::REFRIGERIO === $estado ? array( 'desde' => $desde, 'minutos' => $minutos ) : null,
			'fuera'     => Estado::FUERA_TEMPORAL === $estado ? array( 'desde' => $desde, 'minutos' => $minutos, 'motivo' => $ultima?->motivo?->nombre ?? 'Salida' ) : null,
		);
	}

	/** "3 h 38 min", "45 min". */
	public static function duracion( int $minutos ): string {
		$h = intdiv( $minutos, 60 );
		$m = $minutos % 60;

		return $h ? $h . ' h ' . str_pad( (string) $m, 2, '0', STR_PAD_LEFT ) . ' min' : $m . ' min';
	}

	/** @return array{accion: string, etiqueta: string} */
	private static function accion( string $accion ): array {
		return array( 'accion' => $accion, 'etiqueta' => self::BOTONES[ $accion ] );
	}
}
