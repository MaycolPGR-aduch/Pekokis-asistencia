<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use PHPUnit\Framework\TestCase;

/**
 * Horario base: 09:00 a 19:30, 5 min de tolerancia y 60 de comida → 570 min (9 h 30) de jornada.
 */
final class CalculoJornadaTest extends TestCase {
	private const DIA     = '2026-11-16'; // Lunes.
	private const SABADO  = '2026-11-21';
	private const DOMINGO = '2026-11-22';

	private function zona(): DateTimeZone {
		return new DateTimeZone( 'America/Lima' );
	}

	private function horario(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00', '19:30', 5, 60 );
	}

	private function h( string $hora, string $dia = self::DIA ): string {
		return $dia . ' ' . $hora;
	}

	private function calcular( array $marcas, array $revisiones = array(), ?HorarioDelDia $horario = null, ?ReglasPago $reglas = null, ?string $ausencia = null, string $dia = self::DIA ): CalculoJornada {
		return CalculoJornada::calcular( $marcas, $horario ?? $this->horario(), $dia, $this->zona(), $reglas ?? new ReglasPago(), $revisiones, $ausencia );
	}

	/** Día con comida de una hora. */
	private function diaNormal( string $entrada = '09:00', string $vuelta = '14:00', string $salida = '19:30', string $motivoSalida = 'fin_jornada', string $dia = self::DIA ): array {
		return array(
			Fabrica::entrada( $this->h( $entrada, $dia ), 1 ),
			Fabrica::salida( $this->h( '13:00', $dia ), 'refrigerio', 2 ),
			Fabrica::entrada( $this->h( $vuelta, $dia ), 3 ),
			Fabrica::salida( $this->h( $salida, $dia ), $motivoSalida, 4 ),
		);
	}

	public function test_dia_completo_sin_extra(): void {
		$j = $this->calcular( $this->diaNormal() );

		$this->assertSame( CalculoJornada::COMPLETO, $j->estado );
		$this->assertSame( CalculoJornada::DIA_LABORABLE, $j->tipoDia );
		$this->assertSame( 570, $j->minutosEsperados );
		$this->assertSame( 570, $j->minutosTrabajados );
		$this->assertSame( 60, $j->minutosRefrigerio );
		$this->assertSame( 0, $j->minutosExtra );
		$this->assertSame( 0, $j->minutosNoTrabajados );
	}

	public function test_hora_extra_automatica_sobre_9_h_30(): void {
		$j = $this->calcular( $this->diaNormal( salida: '21:00' ) );

		$this->assertSame( 660, $j->minutosTrabajados );
		$this->assertSame( 90, $j->minutosExtra, 'Sin autorizar nada: lo que pasa de 9 h 30 es extra' );

		$tarde = $this->calcular( $this->diaNormal( '09:30', salida: '20:00' ) );
		$this->assertSame( 0, $tarde->minutosExtra, 'Llegó 30 min tarde y se quedó 30 min más: no hay extra' );
		$this->assertSame( 30, $tarde->minutosTardanza );
	}

	public function test_tardanza_y_exceso_de_comida(): void {
		$j = $this->calcular( $this->diaNormal( '09:14', '14:10' ) );

		$this->assertSame( 14, $j->minutosTardanza, 'Por defecto se cuenta desde la hora de entrada' );
		$this->assertSame( 10, $j->minutosExcesoRefrigerio );
		$this->assertSame( 24, $j->minutosNoTrabajados, 'Se informa; no se descuenta' );

		$desdeTolerancia = new ReglasPago( tardanzaDesde: ReglasPago::TARDANZA_DESDE_TOLERANCIA );
		$this->assertSame( 9, $this->calcular( $this->diaNormal( '09:14' ), reglas: $desdeTolerancia )->minutosTardanza );
		$this->assertSame( 0, $this->calcular( $this->diaNormal( '09:05' ) )->minutosTardanza );
	}

	public function test_comida_no_marcada_se_descuenta(): void {
		$sinComida = array( Fabrica::entrada( $this->h( '09:00' ), 1 ), Fabrica::salida( $this->h( '19:30' ), 'fin_jornada', 2 ) );

		$j = $this->calcular( $sinComida );
		$this->assertSame( 60, $j->minutosComidaDescontada );
		$this->assertSame( 570, $j->minutosTrabajados );
		$this->assertSame( 0, $j->minutosExtra, 'Sin el descuento serían 60 min de extra' );

		$sinRegla = $this->calcular( $sinComida, reglas: new ReglasPago( comidaNoMarcadaMin: 0 ) );
		$this->assertSame( 0, $sinRegla->minutosExtra, 'Lo trabajado dentro del horario no es extra, aunque no haya comido' );
		$this->assertSame( 630, $sinRegla->minutosTrabajados );
	}

	public function test_la_comida_corta_no_suma_minutos(): void {
		$corta = array(
			Fabrica::entrada( $this->h( '09:00' ), 1 ),
			Fabrica::salida( $this->h( '13:00' ), 'refrigerio', 2 ),
			Fabrica::entrada( $this->h( '13:30' ), 3 ),
			Fabrica::salida( $this->h( '19:30' ), 'fin_jornada', 4 ),
		);
		$j = $this->calcular( $corta );

		$this->assertSame( 30, $j->minutosComidaDescontada, 'Cuenta como mínimo la hora de su horario' );
		$this->assertSame( 570, $j->minutosTrabajados );
		$this->assertSame( 0, $j->minutosExtra );
		$this->assertSame( 0, $j->minutosNoTrabajados );

		$corta[3] = Fabrica::salida( $this->h( '20:00' ), 'fin_jornada', 4 );
		$this->assertSame( 30, $this->calcular( $corta )->minutosExtra, 'Solo cuentan los 30 min después de la salida' );
	}

	public function test_despues_de_la_salida_cuenta_por_minutos(): void {
		$this->assertSame( 17, $this->calcular( $this->diaNormal( salida: '19:47' ) )->minutosExtra );
	}

	public function test_antes_de_la_entrada_solo_horas_completas(): void {
		$j = $this->calcular( $this->diaNormal( '07:10' ) );
		$this->assertSame( 110, $j->minutosTemprano, 'Los minutos quedan anotados' );
		$this->assertSame( 60, $j->minutosExtra, 'Pero solo la hora completa es extra' );
		$this->assertSame( 0, $j->minutosMadrugada );

		$this->assertSame( 0, $this->calcular( $this->diaNormal( '08:55' ) )->minutosExtra, 'Llegar 5 minutos antes no es extra' );

		// Llegó a las 7 y se fue a las 18:30: primero completa el horario con lo de la mañana.
		$cubre = $this->calcular( $this->diaNormal( '07:00', salida: '18:30' ) );
		$this->assertSame( 60, $cubre->minutosExtra );
		$this->assertSame( 0, $cubre->minutosNoTrabajados );
		$this->assertSame( 60, $cubre->minutosSalidaAnticipada, 'La salida anticipada se señala igual' );
	}

	public function test_madrugada_hasta_las_6_y_hasta_su_entrada(): void {
		$j = $this->calcular( $this->diaNormal( '05:00' ) );
		$this->assertSame( 240, $j->minutosMadrugada, 'Llegó antes de las 6: madrugada hasta su entrada (9:00)' );
		$this->assertSame( 0, $j->minutosExtra, 'La madrugada se paga aparte y no completa el horario' );
		$this->assertSame( 0, $j->minutosTemprano );
		$this->assertSame( 240, $j->minutosAdicionales() );

		$falta = $this->calcular( $this->diaNormal( '05:00', salida: '18:30' ) );
		$this->assertSame( 60, $falta->minutosNoTrabajados, 'La madrugada no cubre lo que faltó del horario' );

		$domingo = $this->calcular( array( Fabrica::entrada( $this->h( '04:00', self::DOMINGO ), 1 ), Fabrica::salida( $this->h( '08:00', self::DOMINGO ), 'fin_jornada', 2 ) ), horario: HorarioDelDia::libre(), dia: self::DOMINGO );
		$this->assertSame( 120, $domingo->minutosMadrugada, 'También en domingo' );
		$this->assertSame( 120, $domingo->minutosDomingo );

		// Volvió en la noche y pasó la medianoche: de 00:00 a 01:00 es madrugada.
		$noche = $this->diaNormal();
		$noche[] = Fabrica::entrada( $this->h( '23:00' ), 5 );
		$noche[] = Fabrica::salida( '2026-11-17 01:00', 'fin_jornada', 6 );
		$n = $this->calcular( $noche );
		$this->assertSame( 60, $n->minutosMadrugada );
		$this->assertSame( 60, $n->minutosExtra );
	}

	public function test_ajuste_de_gerencia(): void {
		$suma = CalculoJornada::calcular( $this->diaNormal(), $this->horario(), self::DIA, $this->zona(), new ReglasPago(), array(), null, 90 );
		$this->assertSame( 90, $suma->minutosExtra, 'Con el horario completo, lo sumado es extra' );
		$this->assertSame( 660, $suma->minutosTrabajados );
		$this->assertSame( 90, $suma->minutosAjuste );

		$cubre = CalculoJornada::calcular( $this->diaNormal( salida: '18:30' ), $this->horario(), self::DIA, $this->zona(), new ReglasPago(), array(), null, 90 );
		$this->assertSame( 30, $cubre->minutosExtra, 'Primero completa la hora que faltó' );
		$this->assertSame( 0, $cubre->minutosNoTrabajados );

		$resta = CalculoJornada::calcular( $this->diaNormal( salida: '20:00' ), $this->horario(), self::DIA, $this->zona(), new ReglasPago(), array(), null, -60 );
		$this->assertSame( 0, $resta->minutosExtra );
		$this->assertSame( 30, $resta->minutosNoTrabajados );

		$sinMarcas = CalculoJornada::calcular( array(), $this->horario(), self::DIA, $this->zona(), new ReglasPago(), array(), null, 570 );
		$this->assertSame( CalculoJornada::COMPLETO, $sinMarcas->estado, 'Si gerencia le reconoce las horas, no es falta' );
		$this->assertSame( 570, $sinMarcas->minutosTrabajados );
		$this->assertSame( 0, $sinMarcas->minutosNoTrabajados );

		$sabado = CalculoJornada::calcular( array(), HorarioDelDia::libre(), self::SABADO, $this->zona(), new ReglasPago(), array(), null, 120 );
		$this->assertSame( 120, $sabado->minutosSabado );
	}

	public function test_sabado_domingo_y_feriado(): void {
		$sabado = $this->calcular( $this->diaNormal( dia: self::SABADO ), horario: HorarioDelDia::libre(), dia: self::SABADO );
		$this->assertSame( CalculoJornada::DIA_SABADO, $sabado->tipoDia );
		$this->assertSame( 570, $sabado->minutosSabado, 'El sábado todas las horas van con su tarifa' );
		$this->assertSame( 0, $sabado->minutosExtra );
		$this->assertSame( 0, $sabado->minutosComidaDescontada, 'La comida no marcada solo se descuenta en días laborables' );

		$domingo = $this->calcular( array( Fabrica::entrada( $this->h( '10:00', self::DOMINGO ), 1 ), Fabrica::salida( $this->h( '14:00', self::DOMINGO ), 'fin_jornada', 2 ) ), horario: HorarioDelDia::libre(), dia: self::DOMINGO );
		$this->assertSame( 240, $domingo->minutosDomingo );

		$feriado = $this->calcular( array( Fabrica::entrada( $this->h( '09:00' ), 1 ), Fabrica::salida( $this->h( '13:00' ), 'fin_jornada', 2 ) ), horario: HorarioDelDia::libre( 'Feriado: Prueba', true ) );
		$this->assertSame( CalculoJornada::DIA_FERIADO, $feriado->tipoDia );
		$this->assertSame( 240, $feriado->minutosFeriado );
		$this->assertSame( 0, $feriado->minutosNoTrabajados );
		$this->assertSame( 4 * 60, $feriado->minutosAdicionales() );
	}

	public function test_dia_libre_de_su_horario_es_todo_extra(): void {
		$j = $this->calcular( array( Fabrica::entrada( $this->h( '09:00' ), 1 ), Fabrica::salida( $this->h( '12:00' ), 'fin_jornada', 2 ) ), horario: HorarioDelDia::libre() );

		$this->assertSame( CalculoJornada::DIA_LIBRE, $j->tipoDia );
		$this->assertSame( 180, $j->minutosExtra );
	}

	public function test_encargo_cuenta_como_trabajado(): void {
		$marcas = array(
			Fabrica::entrada( $this->h( '09:00' ), 1 ),
			Fabrica::salida( $this->h( '11:00' ), 'encargo', 2 ),
			Fabrica::entrada( $this->h( '12:00' ), 3 ),
			Fabrica::salida( $this->h( '13:00' ), 'refrigerio', 4 ),
			Fabrica::entrada( $this->h( '14:00' ), 5 ),
			Fabrica::salida( $this->h( '19:30' ), 'fin_jornada', 6 ),
		);
		$j = $this->calcular( $marcas );

		$this->assertSame( 60, $j->minutosSalidasPagadas );
		$this->assertSame( 570, $j->minutosTrabajados );
	}

	public function test_salida_personal_no_se_paga_y_espera_revision(): void {
		$marcas = array(
			Fabrica::entrada( $this->h( '09:00' ), 1 ),
			Fabrica::salida( $this->h( '11:00' ), 'personal', 2 ),
			Fabrica::entrada( $this->h( '12:00' ), 3 ),
			Fabrica::salida( $this->h( '13:00' ), 'refrigerio', 4 ),
			Fabrica::entrada( $this->h( '14:00' ), 5 ),
			Fabrica::salida( $this->h( '19:30' ), 'fin_jornada', 6 ),
		);

		$pendiente = $this->calcular( $marcas );
		$this->assertSame( CalculoJornada::A_REVISAR, $pendiente->estado );
		$this->assertSame( 510, $pendiente->minutosTrabajados );

		$aprobada = $this->calcular( $marcas, array( 2 => 'aprobada' ) );
		$this->assertSame( CalculoJornada::COMPLETO, $aprobada->estado );
		$this->assertSame( 0, $aprobada->minutosSalidasPagadas, 'Aprobado es permiso, pero no se paga' );
	}

	public function test_salida_anticipada(): void {
		$this->assertSame( 60, $this->calcular( $this->diaNormal( salida: '18:30' ) )->minutosSalidaAnticipada );

		$emergencia = $this->diaNormal( salida: '18:30', motivoSalida: 'emergencia' );
		$this->assertSame( 0, $this->calcular( $emergencia, array( 4 => 'aprobada' ) )->minutosSalidaAnticipada );
	}

	public function test_dias_sin_marcas(): void {
		$falta = $this->calcular( array() );
		$this->assertSame( CalculoJornada::FALTA, $falta->estado );
		$this->assertSame( 570, $falta->minutosNoTrabajados );

		$vacaciones = $this->calcular( array(), ausencia: 'Vacaciones' );
		$this->assertSame( CalculoJornada::JUSTIFICADO, $vacaciones->estado );
		$this->assertSame( 'Vacaciones', $vacaciones->detalle );

		$this->assertSame( CalculoJornada::DESCANSO, $this->calcular( array(), horario: HorarioDelDia::libre() )->estado );
	}

	public function test_sin_salida_queda_incompleto(): void {
		$j = $this->calcular( array( Fabrica::entrada( $this->h( '09:00' ), 1 ) ) );

		$this->assertSame( CalculoJornada::INCOMPLETO, $j->estado );
		$this->assertSame( 0, $j->minutosTrabajados, 'Un tramo sin cerrar no se cuenta' );
		$this->assertSame( 0, $j->minutosComidaDescontada );
	}
}
