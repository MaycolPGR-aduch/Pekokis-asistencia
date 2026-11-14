<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\AutorizacionSobretiempo;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionPago;
use PHPUnit\Framework\TestCase;

/**
 * Sueldo de S/ 3000 al mes → valor hora S/ 12,50 (3000 ÷ 30 ÷ 8).
 */
final class PrePlanillaTest extends TestCase {
	private function zona(): DateTimeZone {
		return new DateTimeZone( 'America/Lima' );
	}

	private function laborable(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00', '19:30', 5, 60 );
	}

	private function falta( string $dia ): CalculoJornada {
		return CalculoJornada::calcular( array(), $this->laborable(), $dia, $this->zona(), new ReglasPago() );
	}

	private function descanso( string $dia ): CalculoJornada {
		return CalculoJornada::calcular( array(), HorarioDelDia::libre(), $dia, $this->zona(), new ReglasPago() );
	}

	/** Día completo; si $hasta pasa de 19:30 y se autoriza, hay horas extra. */
	private function trabajado( string $dia, string $hasta = '19:30', bool $autorizar = false ): CalculoJornada {
		$marcas = array(
			Fabrica::entrada( $dia . ' 09:00', 1 ),
			Fabrica::salida( $dia . ' 13:00', 'refrigerio', 2 ),
			Fabrica::entrada( $dia . ' 14:00', 3 ),
			Fabrica::salida( $dia . ' ' . $hasta, 'fin_jornada', 4 ),
		);
		$aut = $autorizar ? array( AutorizacionSobretiempo::deHoras( $dia, '19:30', $hasta, $this->zona() ) ) : array();

		return CalculoJornada::calcular( $marcas, $this->laborable(), $dia, $this->zona(), new ReglasPago(), array(), $aut );
	}

	private function mensual( int $soles = 3000, string $desde = '2026-01-01' ): Sueldo {
		return new Sueldo( Sueldo::MENSUAL, $soles * 100, $desde );
	}

	public function test_valor_hora(): void {
		$this->assertEqualsWithDelta( 1250.0, $this->mensual()->valorHora( new ReglasPago() ), 0.001 );
		$this->assertEqualsWithDelta( 1000.0, ( new Sueldo( Sueldo::POR_HORA, 1000, '2026-01-01' ) )->valorHora( new ReglasPago() ), 0.001 );
	}

	public function test_mes_completo_cobra_el_sueldo(): void {
		$jornadas = array();

		for ( $d = 1; $d <= 30; $d++ ) {
			$dia              = sprintf( '2026-11-%02d', $d );
			$jornadas[ $dia ] = $this->descanso( $dia );
		}

		$fila = PrePlanilla::calcular( $jornadas, array( $this->mensual() ), new ReglasPago() );

		$this->assertSame( 300000, $fila->montos['base'] );
		$this->assertSame( 300000, $fila->montos['total'] );
	}

	public function test_falta_y_horas_extra_en_soles(): void {
		$jornadas = array(
			'2026-11-16' => $this->falta( '2026-11-16' ),
			'2026-11-17' => $this->trabajado( '2026-11-17', '21:30', true ),
		);
		$fila = PrePlanilla::calcular( $jornadas, array( $this->mensual() ), new ReglasPago() );

		$this->assertSame( 1, $fila->dias['faltas'] );
		$this->assertSame( 1, $fila->dias['trabajados'] );
		$this->assertSame( 120, $fila->minutos['extra_1'] );
		$this->assertSame( 20000, $fila->montos['base'], '2 de 30 días del mes' );
		$this->assertSame( 10000, $fila->montos['descuento'], 'Un día = 8 horas de S/ 12,50' );
		$this->assertSame( 3125, $fila->montos['extra_1'], '2 h × 12,50 × 1,25' );
		$this->assertSame( 13125, $fila->montos['total'] );

		$sinDescuento = PrePlanilla::calcular( $jornadas, array( $this->mensual() ), new ReglasPago( descontarNoTrabajado: false ) );
		$this->assertSame( 0, $sinDescuento->montos['descuento'] );
	}

	public function test_dia_incompleto_no_se_descuenta(): void {
		$dia  = '2026-11-16';
		$j    = CalculoJornada::calcular( array( Fabrica::entrada( $dia . ' 09:00', 1 ) ), $this->laborable(), $dia, $this->zona(), new ReglasPago() );
		$fila = PrePlanilla::calcular( array( $dia => $j ), array( $this->mensual() ), new ReglasPago() );

		$this->assertSame( 1, $fila->dias['incompletos'] );
		$this->assertSame( 0, $fila->montos['descuento'], 'Olvidó marcar la salida: se corrige antes de descontar' );
	}

	public function test_feriado_trabajado_con_sobretasa(): void {
		$dia    = '2026-12-08';
		$marcas = array( Fabrica::entrada( $dia . ' 09:00', 1 ), Fabrica::salida( $dia . ' 13:00', 'fin_jornada', 2 ) );
		$j      = CalculoJornada::calcular( $marcas, HorarioDelDia::libre( 'Feriado' ), $dia, $this->zona(), new ReglasPago(), array(), array( AutorizacionSobretiempo::deHoras( $dia, '09:00', '13:00', $this->zona() ) ) );
		$fila   = PrePlanilla::calcular( array( $dia => $j ), array( $this->mensual() ), new ReglasPago() );

		$this->assertSame( 1, $fila->dias['descanso_trabajados'] );
		$this->assertSame( 10000, $fila->montos['descanso'], '4 h × 12,50 × 2' );
	}

	public function test_pago_por_hora(): void {
		$fila = PrePlanilla::calcular(
			array(
				'2026-11-16' => $this->trabajado( '2026-11-16' ),
				'2026-11-17' => $this->falta( '2026-11-17' ),
			),
			array( new Sueldo( Sueldo::POR_HORA, 1000, '2026-01-01' ) ),
			new ReglasPago()
		);

		$this->assertSame( 9500, $fila->montos['base'], '9,5 h × S/ 10' );
		$this->assertSame( 0, $fila->montos['descuento'], 'Por hora no se descuenta: solo se paga lo trabajado' );
	}

	public function test_cambio_de_sueldo_y_dias_sin_sueldo(): void {
		$historial = array(
			new Sueldo( Sueldo::MENSUAL, 300000, '2026-11-01', '2026-11-15' ),
			new Sueldo( Sueldo::MENSUAL, 360000, '2026-11-16' ),
		);

		$this->assertSame( 300000, Sueldo::vigenteEn( $historial, '2026-11-15' )->montoCentimos );
		$this->assertSame( 360000, Sueldo::vigenteEn( $historial, '2026-11-16' )->montoCentimos );
		$this->assertNull( Sueldo::vigenteEn( $historial, '2026-10-31' ) );

		$jornadas = array();

		for ( $d = 1; $d <= 30; $d++ ) {
			$dia              = sprintf( '2026-11-%02d', $d );
			$jornadas[ $dia ] = $this->descanso( $dia );
		}

		$fila = PrePlanilla::calcular( $jornadas, $historial, new ReglasPago() );
		$this->assertSame( 330000, $fila->montos['base'], '15 días a 3000 y 15 a 3600' );
		$this->assertTrue( $fila->cambioDeSueldo );
		$this->assertSame( 360000, $fila->sueldoFinal->montoCentimos );

		$sinSueldo = PrePlanilla::calcular( $jornadas, array( new Sueldo( Sueldo::MENSUAL, 300000, '2026-11-11' ) ), new ReglasPago() );
		$this->assertSame( 10, $sinSueldo->diasSinSueldo );
	}

	public function test_montos_escritos_de_varias_formas(): void {
		$this->assertSame( 150000, Sueldo::aCentimos( '1500' ) );
		$this->assertSame( 150050, Sueldo::aCentimos( '1500.50' ) );
		$this->assertSame( 150050, Sueldo::aCentimos( '1.500,50' ) );
		$this->assertSame( 150050, Sueldo::aCentimos( '1,500.50' ) );
		$this->assertSame( 150000, Sueldo::aCentimos( 'S/ 1.500' ) );
		$this->assertSame( 1250, Sueldo::aCentimos( '12,50' ) );
		$this->assertNull( Sueldo::aCentimos( 'mil' ) );
		$this->assertNull( Sueldo::aCentimos( '-5' ) );
		$this->assertSame( 'S/ 1,500.50', Sueldo::soles( 150050 ) );
		$this->assertSame( '2:05', PrePlanilla::horas( 125 ) );
	}

	public function test_validacion_de_sueldo(): void {
		$ok = ValidacionPago::normalizarSueldo( array( 'modalidad' => 'mensual', 'monto' => '1500', 'vigente_desde' => '2026-12-01' ) );
		$this->assertSame( array(), ValidacionPago::erroresSueldo( $ok ) );
		$this->assertArrayHasKey( 'vigente_desde', ValidacionPago::erroresSueldo( $ok, '2026-12-01' ), 'Tiene que regir después del actual' );

		$mal = ValidacionPago::normalizarSueldo( array( 'modalidad' => 'semanal', 'monto' => '0', 'vigente_desde' => 'ayer' ) );
		$this->assertSame( array( 'modalidad', 'monto', 'vigente_desde' ), array_keys( ValidacionPago::erroresSueldo( $mal ) ) );

		$alto = ValidacionPago::normalizarSueldo( array( 'modalidad' => 'por_hora', 'monto' => '5000', 'vigente_desde' => '2026-12-01' ) );
		$this->assertArrayHasKey( 'monto', ValidacionPago::erroresSueldo( $alto ) );
	}

	public function test_validacion_de_sobretiempo(): void {
		$base = array( 'trabajador_id' => 3, 'fecha' => '2026-11-16', 'desde' => '19:30', 'hasta' => '21:00', 'compensacion' => 'pago', 'motivo' => 'Pedido urgente de colegio' );

		$this->assertSame( array(), ValidacionPago::erroresSobretiempo( ValidacionPago::normalizarSobretiempo( $base ) ) );

		$cruce = ValidacionPago::erroresSobretiempo( ValidacionPago::normalizarSobretiempo( $base ), array( array( 'desde' => '20:00:00', 'hasta' => '22:00:00' ) ) );
		$this->assertArrayHasKey( 'desde', $cruce );

		$this->assertSame( array(), ValidacionPago::erroresSobretiempo( ValidacionPago::normalizarSobretiempo( $base ), array( array( 'desde' => '21:00', 'hasta' => '22:00' ) ) ), 'Franjas seguidas no se cruzan' );

		$medianoche = ValidacionPago::normalizarSobretiempo( array( 'desde' => '22:00', 'hasta' => '01:00' ) + $base );
		$this->assertSame( array(), ValidacionPago::erroresSobretiempo( $medianoche ) );
		$this->assertSame( 180, ValidacionPago::minutos( '22:00', '01:00' ) );

		$mal = ValidacionPago::erroresSobretiempo( ValidacionPago::normalizarSobretiempo( array( 'desde' => '25:00', 'compensacion' => 'otra', 'motivo' => 'ok' ) + $base ) );
		$this->assertSame( array( 'desde', 'compensacion', 'motivo' ), array_keys( $mal ) );
	}
}
