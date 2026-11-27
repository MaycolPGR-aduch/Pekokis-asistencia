<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionPago;
use PHPUnit\Framework\TestCase;

/**
 * Pago por recibos por honorarios: honorario + horas por la tarifa de cada tipo.
 */
final class PrePlanillaTest extends TestCase {
	private function zona(): DateTimeZone {
		return new DateTimeZone( 'America/Lima' );
	}

	private function laborable(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00', '19:30', 5, 60 );
	}

	/** Día con comida de 13 a 14 y salida a la hora indicada. */
	private function dia( string $dia, string $salida = '19:30', ?HorarioDelDia $h = null, string $entrada = '09:00' ): CalculoJornada {
		$marcas = array(
			Fabrica::entrada( $dia . ' ' . $entrada, 1 ),
			Fabrica::salida( $dia . ' 13:00', 'refrigerio', 2 ),
			Fabrica::entrada( $dia . ' 14:00', 3 ),
			Fabrica::salida( $dia . ' ' . $salida, 'fin_jornada', 4 ),
		);

		return CalculoJornada::calcular( $marcas, $h ?? $this->laborable(), $dia, $this->zona(), new ReglasPago() );
	}

	private function falta( string $dia ): CalculoJornada {
		return CalculoJornada::calcular( array(), $this->laborable(), $dia, $this->zona(), new ReglasPago() );
	}

	/** Honorario S/ 1,800 (valor hora S/ 9.38); extra S/ 12; domingo S/ 20; feriado S/ 24. */
	private function tarifa( string $desde = '2026-01-01', ?string $hasta = null, int $honorario = 180000 ): Sueldo {
		return new Sueldo( $honorario, 1200, 1400, 2000, 2400, $desde, $hasta );
	}

	public function test_ejemplo_de_las_maquetas(): void {
		// Lucía Ramos, noviembre: 6,5 h extra y 4 h de sábado con la semana completa → 1,800 + 78 + 48 = S/ 1,926.
		$jornadas = array(
			'2026-11-16' => $this->dia( '2026-11-16', '22:00' ),   // 2 h 30 extra.
			'2026-11-17' => $this->dia( '2026-11-17', '23:30' ),   // 4 h extra.
			'2026-11-21' => CalculoJornada::calcular(
				array( Fabrica::entrada( '2026-11-21 09:00', 1 ), Fabrica::salida( '2026-11-21 13:00', 'fin_jornada', 2 ) ),
				HorarioDelDia::libre(),
				'2026-11-21',
				$this->zona(),
				new ReglasPago()
			),
		);
		$fila = PrePlanilla::calcular( $jornadas, array( $this->tarifa() ) );

		$this->assertSame( 390, $fila->minutos['extra'] );
		$this->assertSame( 240, $fila->minutos['sabado'] );
		$this->assertSame( 180000, $fila->montos['honorario'] );
		$this->assertSame( 7800, $fila->montos['extra'] );
		$this->assertSame( 4800, $fila->montos['sabado'], 'Completó la semana: el sábado va con la tarifa extra' );
		$this->assertSame( 240, $fila->minutos['sabado_extra'] );
		$this->assertSame( 192600, $fila->montos['total'] );
		$this->assertSame( 12600, $fila->costoExtra() );
		$this->assertSame( 1, $fila->dias['sabados'] );
		$this->assertSame( PrePlanilla::LISTO, $fila->estado() );
	}

	public function test_no_hay_descuentos_por_faltas_ni_tardanzas(): void {
		$fila = PrePlanilla::calcular(
			array(
				'2026-11-16' => $this->falta( '2026-11-16' ),
				'2026-11-17' => $this->dia( '2026-11-17', entrada: '09:40' ),
			),
			array( $this->tarifa() )
		);

		$this->assertSame( 1, $fila->dias['faltas'] );
		$this->assertSame( 1, $fila->dias['con_tardanza'] );
		$this->assertSame( 180000, $fila->montos['total'], 'Se señalan, pero no se descuentan' );
	}

	public function test_domingo_y_feriado_con_su_tarifa(): void {
		$domingo = CalculoJornada::calcular( array( Fabrica::entrada( '2026-11-22 10:00', 1 ), Fabrica::salida( '2026-11-22 13:00', 'fin_jornada', 2 ) ), HorarioDelDia::libre(), '2026-11-22', $this->zona(), new ReglasPago() );
		$feriado = CalculoJornada::calcular( array( Fabrica::entrada( '2026-12-08 09:00', 1 ), Fabrica::salida( '2026-12-08 11:00', 'fin_jornada', 2 ) ), HorarioDelDia::libre( 'Feriado', true ), '2026-12-08', $this->zona(), new ReglasPago() );
		$fila    = PrePlanilla::calcular( array( '2026-11-22' => $domingo, '2026-12-08' => $feriado ), array( $this->tarifa() ) );

		$this->assertSame( 6000, $fila->montos['domingo'], '3 h × 20' );
		$this->assertSame( 4800, $fila->montos['feriado'], '2 h × 24' );
	}

	public function test_incidencia_frena_el_pago(): void {
		$incompleto = CalculoJornada::calcular( array( Fabrica::entrada( '2026-11-16 09:00', 1 ) ), $this->laborable(), '2026-11-16', $this->zona(), new ReglasPago() );
		$fila       = PrePlanilla::calcular( array( '2026-11-16' => $incompleto ), array( $this->tarifa() ) );

		$this->assertSame( PrePlanilla::INCIDENCIA, $fila->estado() );
		$this->assertSame( PrePlanilla::SIN_TARIFA, PrePlanilla::calcular( array( '2026-11-16' => $this->dia( '2026-11-16' ) ), array() )->estado() );
	}

	public function test_cambio_de_tarifa_en_el_mes(): void {
		$historial = array(
			$this->tarifa( '2026-01-01', '2026-11-16' ),
			new Sueldo( 200000, 1500, 1400, 2000, 2400, '2026-11-17' ),
		);
		$fila = PrePlanilla::calcular(
			array(
				'2026-11-16' => $this->dia( '2026-11-16', '20:30' ), // 1 h extra a 12.
				'2026-11-17' => $this->dia( '2026-11-17', '20:30' ), // 1 h extra a 15.
			),
			$historial
		);

		$this->assertSame( 2700, $fila->montos['extra'] );
		$this->assertSame( 200000, $fila->montos['honorario'], 'El honorario es el vigente al final del mes' );
		$this->assertTrue( $fila->cambioDeSueldo );
		$this->assertNull( Sueldo::vigenteEn( $historial, '2025-12-31' ) );
	}

	public function test_explicacion_en_palabras(): void {
		$fila = PrePlanilla::calcular( array( '2026-11-16' => $this->dia( '2026-11-16', '22:00' ) ), array( $this->tarifa() ) );
		$texto = implode( "\n", $fila->explicacion() );

		$this->assertStringContainsString( 'Honorario del mes: S/ 1,800.00', $texto );
		$this->assertStringContainsString( 'Horas extra de lunes a viernes: 2,5 h × S/ 12.00 = S/ 30.00.', $texto );
		$this->assertStringContainsString( 'Total: S/ 1,830.00.', $texto );
	}

	public function test_suma_del_equipo_y_puntualidad(): void {
		$rosa = PrePlanilla::calcular( array( '2026-11-16' => $this->dia( '2026-11-16', '21:30' ), '2026-11-17' => $this->falta( '2026-11-17' ) ), array( $this->tarifa() ) );
		$luis = PrePlanilla::calcular( array( '2026-11-16' => $this->dia( '2026-11-16', entrada: '09:20' ), '2026-11-17' => $this->dia( '2026-11-17' ) ), array() );

		$this->assertSame( 100, $rosa->puntualidad() );
		$this->assertSame( 50, $luis->puntualidad() );

		$equipo = PrePlanilla::sumar( array( $rosa, $luis ) );
		$this->assertSame( 3, $equipo->dias['trabajados'] );
		$this->assertSame( 120, $equipo->minutos['extra'] );
		$this->assertSame( 67, $equipo->puntualidad() );
		$this->assertNull( ( new PrePlanilla() )->puntualidad() );
	}

	public function test_montos_escritos_de_varias_formas(): void {
		$this->assertSame( 150000, Sueldo::aCentimos( '1500' ) );
		$this->assertSame( 150050, Sueldo::aCentimos( '1.500,50' ) );
		$this->assertSame( 150050, Sueldo::aCentimos( '1,500.50' ) );
		$this->assertSame( 1250, Sueldo::aCentimos( '12,50' ) );
		$this->assertSame( 0, Sueldo::aCentimos( '0' ) );
		$this->assertNull( Sueldo::aCentimos( 'mil' ) );
		$this->assertSame( 'S/ 1,500.50', Sueldo::soles( 150050 ) );
		$this->assertSame( '6,5', PrePlanilla::horasDecimal( 390 ) );
		$this->assertSame( '4', PrePlanilla::horasDecimal( 240 ) );
	}

	public function test_validacion_de_honorario_y_tarifas(): void {
		$base = array( 'honorario' => '1800', 'tarifa_extra' => '12', 'tarifa_sabado' => '14', 'tarifa_domingo' => '20', 'tarifa_feriado' => '0', 'vigente_desde' => '2026-12-01' );
		$ok   = ValidacionPago::normalizarSueldo( $base );

		$this->assertSame( array(), ValidacionPago::erroresSueldo( $ok ) );
		$this->assertSame( 0, $ok['tarifas']['feriado'], 'Una tarifa puede ser 0' );
		$this->assertArrayHasKey( 'vigente_desde', ValidacionPago::erroresSueldo( $ok, '2026-12-01' ) );

		$mal = ValidacionPago::normalizarSueldo( array( 'honorario' => '0', 'tarifa_extra' => 'x', 'tarifa_domingo' => '5000', 'vigente_desde' => 'ayer' ) + $base );
		$this->assertSame( array( 'honorario', 'tarifa_extra', 'tarifa_domingo', 'vigente_desde' ), array_keys( ValidacionPago::erroresSueldo( $mal ) ) );

		$this->assertSame( Sueldo::FIJO, $ok['modo'] );
		$horas = ValidacionPago::normalizarSueldo( array( 'modo' => 'horas', 'aparte' => array( 'extra', 'raro' ) ) + $base );
		$this->assertSame( Sueldo::HORAS, $horas['modo'] );
		$this->assertSame( array( 'extra' ), $horas['aparte'] );
	}

	/** Una semana de lunes (16-11) a sábado (21-11); $lunesFalta deja el lunes sin marcas. */
	private function semana( bool $lunesFalta = false, ?string $ausencia = null ): array {
		$j = array();
		foreach ( array( '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20' ) as $i => $d ) {
			$j[ $d ] = 0 === $i && $lunesFalta ? CalculoJornada::calcular( array(), $this->laborable(), $d, $this->zona(), new ReglasPago(), array(), $ausencia ) : $this->dia( $d );
		}
		$j['2026-11-21'] = CalculoJornada::calcular( array( Fabrica::entrada( '2026-11-21 09:00', 1 ), Fabrica::salida( '2026-11-21 13:00', 'fin_jornada', 2 ) ), HorarioDelDia::libre(), '2026-11-21', $this->zona(), new ReglasPago() );

		return $j;
	}

	public function test_valor_hora_es_el_honorario_entre_192(): void {
		$this->assertSame( 938, $this->tarifa()->valorHora(), '1,800 ÷ 192 = 9.375' );
		$this->assertSame( 1042, $this->tarifa( honorario: 200000 )->valorHora() );
	}

	public function test_el_sabado_depende_de_la_semana(): void {
		$completa = PrePlanilla::calcular( $this->semana(), array( $this->tarifa() ) );
		$this->assertSame( 4800, $completa->montos['sabado'], '4 h × tarifa extra' );
		$this->assertTrue( PrePlanilla::semanas( $this->semana() )['2026-11-16']['completa'] );

		$incompleta = PrePlanilla::calcular( $this->semana( true ), array( $this->tarifa() ) );
		$this->assertSame( 3752, $incompleta->montos['sabado'], 'Faltó el lunes: 4 h × valor hora (9.38)' );
		$this->assertSame( 240, $incompleta->minutos['sabado_normal'] );
		$this->assertStringContainsString( 'Sábado sin completar la semana: 4 h × S/ 9.38 (valor hora) = S/ 37.52.', implode( "
", $incompleta->explicacion() ) );

		$justificada = PrePlanilla::calcular( $this->semana( true, 'Vacaciones' ), array( $this->tarifa() ) );
		$this->assertSame( 4800, $justificada->montos['sabado'], 'Un día justificado cuenta como cumplido' );

		// La semana se mira en total: una hora menos el lunes y una más el martes, completa.
		$compensa               = $this->semana();
		$compensa['2026-11-16'] = $this->dia( '2026-11-16', '18:30' );
		$compensa['2026-11-17'] = $this->dia( '2026-11-17', '20:30' );
		$this->assertSame( 4800, PrePlanilla::calcular( $compensa, array( $this->tarifa() ) )->montos['sabado'] );

		// La semana puede empezar el mes anterior: se pasan las semanas aparte.
		$soloSabado = array( '2026-11-21' => $this->semana()['2026-11-21'] );
		$this->assertSame( 0, PrePlanilla::calcular( $soloSabado, array( $this->tarifa() ) )->montos['sabado_extra'], 'Sin datos de la semana, va a valor hora' );
		$this->assertSame( 4800, PrePlanilla::calcular( $soloSabado, array( $this->tarifa() ), null, PrePlanilla::semanas( $this->semana() ) )->montos['sabado'] );
		$this->assertSame( '2026-11-16', PrePlanilla::lunes( '2026-11-21' ) );
		$this->assertSame( '2026-11-30', PrePlanilla::lunes( '2026-12-06' ) );
	}

	public function test_madrugada_con_multiplicador_y_descuentos(): void {
		$madruga = array( '2026-11-16' => $this->dia( '2026-11-16', entrada: '05:00' ) ); // 4 h de madrugada.
		$fila    = PrePlanilla::calcular( $madruga, array( $this->tarifa() ), new ReglasPago( multiplicadorMadrugada: 1.5 ), null, 5000 );

		$this->assertSame( 240, $fila->minutos['madrugada'] );
		$this->assertSame( 5628, $fila->montos['madrugada'], '4 h × (9.38 × 1,5)' );
		$this->assertSame( 5000, $fila->montos['descuentos'] );
		$this->assertSame( 180000 + 5628 - 5000, $fila->montos['total'] );
		$texto = implode( "
", $fila->explicacion() );
		$this->assertStringContainsString( 'Madrugada (hasta las 6:00): 4 h × S/ 14.07 (valor hora × 1,5) = S/ 56.28.', $texto );
		$this->assertStringContainsString( 'Descuentos: − S/ 50.00.', $texto );

		$this->assertSame( 0, PrePlanilla::calcular( array(), array( $this->tarifa() ), null, null, 999999 )->montos['total'], 'El total nunca es negativo' );
	}

	public function test_modo_por_horas(): void {
		$dias = array( '2026-11-16' => $this->dia( '2026-11-16', '21:00' ) ); // 11 h: 9 h 30 + 1 h 30 extra.
		$todo = new Sueldo( 180000, 1200, 1200, 2000, 2400, '2026-01-01', null, null, Sueldo::HORAS );
		$fila = PrePlanilla::calcular( $dias, array( $todo ) );

		$this->assertTrue( $fila->porHoras() );
		$this->assertSame( 0, $fila->montos['honorario'] );
		$this->assertSame( 660, $fila->minutos['horas'] );
		$this->assertSame( 10318, $fila->montos['horas'], '11 h × 9.38' );
		$this->assertSame( 0, $fila->montos['extra'] );
		$this->assertSame( 10318, $fila->montos['total'] );

		$aparte = new Sueldo( 180000, 1200, 1200, 2000, 2400, '2026-01-01', null, null, Sueldo::HORAS, array( 'extra' ) );
		$fila2  = PrePlanilla::calcular( $dias, array( $aparte ) );
		$this->assertSame( 570, $fila2->minutos['horas'] );
		$this->assertSame( 8911, $fila2->montos['horas'] );
		$this->assertSame( 1800, $fila2->montos['extra'], '1,5 h × 12' );
		$this->assertSame( 10711, $fila2->montos['total'] );
		$this->assertStringContainsString( 'Pago por horas: 9,5 h × S/ 9.38', implode( "
", $fila2->explicacion() ) );
	}
}
