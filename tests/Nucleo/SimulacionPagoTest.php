<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use Pekokis\Asistencia\Nucleo\SimulacionPago;
use Pekokis\Asistencia\Nucleo\Sueldo;
use PHPUnit\Framework\TestCase;

/**
 * Simulador de pagos: mismo cálculo que la planilla, con valores que no se guardan.
 */
final class SimulacionPagoTest extends TestCase {
	private function dia( string $dia, string $salida, bool $laborable = true ): CalculoJornada {
		$marcas = array(
			Fabrica::entrada( $dia . ' 09:00', 1 ),
			Fabrica::salida( $dia . ' 13:00', 'refrigerio', 2 ),
			Fabrica::entrada( $dia . ' 14:00', 3 ),
			Fabrica::salida( $dia . ' ' . $salida, 'fin_jornada', 4 ),
		);
		$h      = $laborable ? new HorarioDelDia( true, '09:00', '19:30', 5, 60 ) : HorarioDelDia::libre();

		return CalculoJornada::calcular( $marcas, $h, $dia, new DateTimeZone( 'America/Lima' ), new ReglasPago() );
	}

	/** El ejemplo de las maquetas: S/ 1,800 + 6,5 h × 12 + 4 h de sábado con la semana completa × 12 = S/ 1,926. */
	public function test_desde_cero_da_el_ejemplo_de_las_maquetas(): void {
		$r = SimulacionPago::libre( 180000, array( 'extra' => 1200 ), array( 'extra' => 390, 'sabado_extra' => 240 ) );

		$this->assertSame( 7800, $r['montos']['extra'] );
		$this->assertSame( 4800, $r['montos']['sabado_extra'] );
		$this->assertSame( 192600, $r['montos']['total'] );
	}

	public function test_desde_cero_sabado_sin_semana_y_madrugada_a_valor_hora(): void {
		$r = SimulacionPago::libre( 180000, array( 'extra' => 1200 ), array( 'sabado_normal' => 240, 'madrugada' => 60 ), 2.0, 1000 );

		$this->assertSame( 3752, $r['montos']['sabado_normal'], '4 h × 9.38' );
		$this->assertSame( 1876, $r['montos']['madrugada'], '1 h × 9.38 × 2' );
		$this->assertSame( 180000 + 3752 + 1876 - 1000, $r['montos']['total'] );
	}

	public function test_desde_cero_no_acepta_negativos(): void {
		$r = SimulacionPago::libre( -5, array( 'extra' => 1200 ), array( 'extra' => -60 ) );

		$this->assertSame( 0, $r['montos']['total'] );
	}

	/** Con los mismos valores, el simulador da lo mismo que la planilla. */
	public function test_con_los_valores_reales_da_lo_mismo_que_la_planilla(): void {
		$jornadas = array(
			'2026-11-02' => $this->dia( '2026-11-02', '21:00' ),
			'2026-11-03' => $this->dia( '2026-11-03', '20:07' ),
			'2026-11-07' => $this->dia( '2026-11-07', '17:00', false ),
		);
		$sueldo   = new Sueldo( 180000, 1250, 1250, 1600, 2000, '2026-01-01' );
		$real     = SimulacionPago::deReal( PrePlanilla::calcular( $jornadas, array( $sueldo ), null, null, 3000 ) );
		$simulado = SimulacionPago::conJornadas( $jornadas, 180000, array( 'extra' => 1250, 'domingo' => 1600, 'feriado' => 2000 ), array(), null, null, 3000 );

		$this->assertSame( $real['montos'], $simulado['montos'] );
		$this->assertSame( $real['minutos'], $simulado['minutos'] );
	}

	public function test_subir_una_tarifa_y_sumar_horas(): void {
		$jornadas = array( '2026-11-02' => $this->dia( '2026-11-02', '21:00' ) ); // 90 min extra.
		$r        = SimulacionPago::conJornadas( $jornadas, 180000, array( 'extra' => 1400 ), array( 'extra' => 240 ) );

		$this->assertSame( 330, $r['minutos']['extra'] );
		$this->assertSame( 7700, $r['montos']['extra'] ); // 5,5 h × S/ 14.
		$this->assertSame( 187700, $r['montos']['total'] );
	}

	public function test_restar_mas_horas_de_las_que_hay_deja_cero(): void {
		$jornadas = array( '2026-11-02' => $this->dia( '2026-11-02', '21:00' ) );
		$r        = SimulacionPago::conJornadas( $jornadas, 100000, array( 'extra' => 1200 ), array( 'extra' => -600 ) );

		$this->assertSame( 0, $r['minutos']['extra'] );
		$this->assertSame( 0, $r['montos']['extra'] );
		$this->assertSame( 100000, $r['montos']['total'] );
	}

	public function test_por_horas_lo_sumado_va_a_valor_hora(): void {
		$jornadas = array( '2026-11-02' => $this->dia( '2026-11-02', '19:30' ) ); // 9 h 30.
		$r        = SimulacionPago::conJornadas( $jornadas, 180000, array( 'extra' => 1200 ), array( 'horas' => 60, 'extra' => 60 ), null, null, 0, Sueldo::HORAS );

		$this->assertSame( 0, $r['montos']['honorario'] );
		$this->assertSame( 690, $r['minutos']['horas'], '9 h 30 + 1 h normal + 1 h extra, todo a valor hora' );
		$this->assertSame( 0, $r['montos']['extra'] );
		$this->assertSame( (int) round( 570 * 938 / 60 ) + 938 + 938, $r['montos']['total'] );
	}

	public function test_el_honorario_cuenta_aunque_el_mes_no_tenga_dias(): void {
		$r = SimulacionPago::conJornadas( array(), 150000, array() );

		$this->assertSame( 150000, $r['montos']['total'] );
	}
}
