<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\AutorizacionSobretiempo;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use PHPUnit\Framework\TestCase;

/**
 * Horario base: 09:00 a 19:30, 5 min de tolerancia y 60 de refrigerio → 570 min esperados.
 */
final class CalculoJornadaTest extends TestCase {
	private const DIA = '2026-11-16'; // Lunes.

	private function zona(): DateTimeZone {
		return new DateTimeZone( 'America/Lima' );
	}

	private function horario(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00', '19:30', 5, 60 );
	}

	private function h( string $hora ): string {
		return self::DIA . ' ' . $hora;
	}

	private function calcular( array $marcas, array $revisiones = array(), array $aut = array(), ?HorarioDelDia $horario = null, ?ReglasPago $reglas = null, ?string $ausencia = null ): CalculoJornada {
		return CalculoJornada::calcular( $marcas, $horario ?? $this->horario(), self::DIA, $this->zona(), $reglas ?? new ReglasPago(), $revisiones, $aut, $ausencia );
	}

	private function autorizacion( string $desde, string $hasta, string $compensacion = AutorizacionSobretiempo::PAGO ): AutorizacionSobretiempo {
		return AutorizacionSobretiempo::deHoras( self::DIA, $desde, $hasta, $this->zona(), $compensacion );
	}

	/** Día normal con refrigerio de una hora. */
	private function diaNormal( string $entrada = '09:00', string $vuelta = '14:00', string $salida = '19:30', string $motivoSalida = 'fin_jornada' ): array {
		return array(
			Fabrica::entrada( $this->h( $entrada ), 1 ),
			Fabrica::salida( $this->h( '13:00' ), 'refrigerio', 2 ),
			Fabrica::entrada( $this->h( $vuelta ), 3 ),
			Fabrica::salida( $this->h( $salida ), $motivoSalida, 4 ),
		);
	}

	public function test_dia_completo(): void {
		$j = $this->calcular( $this->diaNormal( '08:58' ) );

		$this->assertSame( CalculoJornada::COMPLETO, $j->estado );
		$this->assertSame( 570, $j->minutosEsperados );
		$this->assertSame( 570, $j->minutosOrdinarios );
		$this->assertSame( 572, $j->minutosTrabajados );
		$this->assertSame( 60, $j->minutosRefrigerio );
		$this->assertSame( 0, $j->minutosTardanza );
		$this->assertSame( 0, $j->minutosNoTrabajados );
		$this->assertSame( 2, $j->minutosSinAutorizar, 'Los 2 minutos antes de las 9 quedan a la vista, sin pagarse' );
		$this->assertSame( 0, $j->minutosExtra1 );
	}

	public function test_tardanza_y_exceso_de_refrigerio(): void {
		$j = $this->calcular( $this->diaNormal( '09:14', '14:10' ) );

		$this->assertSame( 14, $j->minutosTardanza, 'Por defecto se cuenta desde la hora de entrada' );
		$this->assertSame( 70, $j->minutosRefrigerio );
		$this->assertSame( 10, $j->minutosExcesoRefrigerio );
		$this->assertSame( 546, $j->minutosOrdinarios );
		$this->assertSame( 24, $j->minutosNoTrabajados, '14 de tardanza + 10 de refrigerio' );

		$desdeTolerancia = new ReglasPago( tardanzaDesde: ReglasPago::TARDANZA_DESDE_TOLERANCIA );
		$this->assertSame( 9, $this->calcular( $this->diaNormal( '09:14' ), reglas: $desdeTolerancia )->minutosTardanza );
	}

	public function test_dentro_de_la_tolerancia_no_es_tardanza(): void {
		$this->assertSame( 0, $this->calcular( $this->diaNormal( '09:05' ) )->minutosTardanza );
		$this->assertSame( 6, $this->calcular( $this->diaNormal( '09:06' ) )->minutosTardanza );
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
		$this->assertSame( 570, $j->minutosOrdinarios );
		$this->assertSame( 0, $j->minutosNoTrabajados );
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
		$this->assertSame( 1, $pendiente->pendientesRevision );
		$this->assertSame( 60, $pendiente->minutosNoTrabajados );

		$aprobada = $this->calcular( $marcas, array( 2 => 'aprobada' ) );
		$this->assertSame( CalculoJornada::COMPLETO, $aprobada->estado );
		$this->assertSame( 60, $aprobada->minutosNoTrabajados, 'Aprobado es permiso, pero sin goce' );
		$this->assertSame( 0, $aprobada->minutosSalidasPagadas );
	}

	public function test_salida_anticipada(): void {
		$this->assertSame( 60, $this->calcular( $this->diaNormal( salida: '18:30' ) )->minutosSalidaAnticipada );

		// Emergencia: si se aprueba, no es salida anticipada (pero tampoco se paga).
		$emergencia = $this->diaNormal( salida: '18:30', motivoSalida: 'emergencia' );
		$this->assertSame( 60, $this->calcular( $emergencia )->minutosSalidaAnticipada );
		$aprobada = $this->calcular( $emergencia, array( 4 => 'aprobada' ) );
		$this->assertSame( 0, $aprobada->minutosSalidaAnticipada );
		$this->assertSame( 60, $aprobada->minutosNoTrabajados );
		$this->assertSame( CalculoJornada::COMPLETO, $aprobada->estado, 'Salir por emergencia y no volver no deja el día incompleto' );
	}

	public function test_encargo_sin_volver_cuenta_hasta_el_fin_del_horario(): void {
		$j = $this->calcular( $this->diaNormal( salida: '17:00', motivoSalida: 'encargo' ) );

		$this->assertSame( 150, $j->minutosSalidasPagadas );
		$this->assertSame( 570, $j->minutosOrdinarios );
		$this->assertSame( 0, $j->minutosSalidaAnticipada );
	}

	public function test_sobretiempo_autorizado_con_dos_tasas(): void {
		$marcas = $this->diaNormal( salida: '22:00' );

		$sin = $this->calcular( $marcas );
		$this->assertSame( 150, $sin->minutosSinAutorizar );
		$this->assertSame( 0, $sin->minutosExtra1 + $sin->minutosExtra2 );

		$con = $this->calcular( $marcas, aut: array( $this->autorizacion( '19:30', '22:00' ) ) );
		$this->assertSame( 120, $con->minutosExtra1, 'Las 2 primeras horas al 25 %' );
		$this->assertSame( 30, $con->minutosExtra2, 'El resto al 35 %' );
		$this->assertSame( 0, $con->minutosSinAutorizar );

		$parcial = $this->calcular( $marcas, aut: array( $this->autorizacion( '19:30', '21:00' ) ) );
		$this->assertSame( 90, $parcial->minutosExtra1 );
		$this->assertSame( 60, $parcial->minutosSinAutorizar, 'Lo que pasó de la franja queda sin autorizar' );

		$descanso = $this->calcular( $marcas, aut: array( $this->autorizacion( '19:30', '22:00', AutorizacionSobretiempo::DESCANSO ) ) );
		$this->assertSame( 150, $descanso->minutosCompensables );
		$this->assertSame( 0, $descanso->minutosExtra1 );
	}

	public function test_franja_autorizada_sin_trabajarla_no_suma(): void {
		$j = $this->calcular( $this->diaNormal(), aut: array( $this->autorizacion( '19:30', '22:00' ) ) );

		$this->assertSame( 0, $j->minutosExtra1 );
	}

	public function test_feriado_trabajado_va_a_su_bolsa(): void {
		$marcas = array(
			Fabrica::entrada( $this->h( '09:00' ), 1 ),
			Fabrica::salida( $this->h( '13:00' ), 'fin_jornada', 2 ),
		);
		$feriado = HorarioDelDia::libre( 'Feriado: Prueba' );

		$con = $this->calcular( $marcas, aut: array( $this->autorizacion( '09:00', '13:00' ) ), horario: $feriado );
		$this->assertSame( 240, $con->minutosDescansoTrabajado );
		$this->assertSame( 0, $con->minutosOrdinarios );
		$this->assertSame( 0, $con->minutosNoTrabajados );
		$this->assertSame( 0, $con->minutosExtra1 );
		$this->assertFalse( $con->laborable );

		$sin = $this->calcular( $marcas, horario: $feriado );
		$this->assertSame( 240, $sin->minutosSinAutorizar );
		$this->assertSame( 0, $sin->minutosDescansoTrabajado );
	}

	public function test_dias_sin_marcas(): void {
		$falta = $this->calcular( array() );
		$this->assertSame( CalculoJornada::FALTA, $falta->estado );
		$this->assertSame( 570, $falta->minutosNoTrabajados );

		$vacaciones = $this->calcular( array(), ausencia: 'Vacaciones' );
		$this->assertSame( CalculoJornada::JUSTIFICADO, $vacaciones->estado );
		$this->assertSame( 0, $vacaciones->minutosNoTrabajados );
		$this->assertSame( 'Vacaciones', $vacaciones->detalle );

		$domingo = $this->calcular( array(), horario: HorarioDelDia::libre() );
		$this->assertSame( CalculoJornada::DESCANSO, $domingo->estado );
	}

	public function test_sin_salida_queda_incompleto(): void {
		$j = $this->calcular( array( Fabrica::entrada( $this->h( '09:00' ), 1 ) ) );

		$this->assertSame( CalculoJornada::INCOMPLETO, $j->estado );
		$this->assertSame( 0, $j->minutosTrabajados, 'Un tramo sin cerrar no se cuenta' );
	}

	public function test_franja_que_pasa_la_medianoche(): void {
		$a = AutorizacionSobretiempo::deHoras( self::DIA, '22:00', '01:00', $this->zona() );

		$this->assertSame( 180, intdiv( $a->hasta->getTimestamp() - $a->desde->getTimestamp(), 60 ) );
	}
}
