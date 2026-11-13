<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\MaquinaEstados;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\Nucleo\TotalesDia;
use PHPUnit\Framework\TestCase;

final class TotalesDiaTest extends TestCase {
	public function test_dia_completo_del_plan(): void {
		// El ejemplo de la sección "Cómo queda un día en el reporte".
		$t = TotalesDia::calcular(
			array(
				Fabrica::entrada( '2026-11-13 08:58' ),
				Fabrica::salida( '2026-11-13 13:00', 'refrigerio' ),
				Fabrica::entrada( '2026-11-13 13:48' ),
				Fabrica::salida( '2026-11-13 15:30', 'personal' ),
				Fabrica::entrada( '2026-11-13 16:05' ),
				Fabrica::salida( '2026-11-13 18:02', 'fin_jornada' ),
			)
		);

		$this->assertSame( 4 * 60 + 2 + 60 + 42 + 60 + 57, $t->minutosDentro ); // 7 h 41 min
		$this->assertSame( 48, $t->minutosRefrigerio );
		$this->assertSame( 1, $t->salidasTemporales );
		$this->assertSame( '2026-11-13 23:02', $t->ultimaSalida?->format( 'Y-m-d H:i' ) );
		$this->assertFalse( $t->incompleto );
	}

	public function test_dia_sin_salida_queda_incompleto(): void {
		$t = TotalesDia::calcular( array( Fabrica::entrada( '2026-11-13 09:00' ) ) );

		$this->assertTrue( $t->incompleto );
		$this->assertSame( 0, $t->minutosDentro );
		$this->assertNull( $t->ultimaSalida );
	}

	public function test_dia_vacio(): void {
		$t = TotalesDia::calcular( array() );

		$this->assertNull( $t->primeraEntrada );
		$this->assertFalse( $t->incompleto );
	}

	public function test_secuencia_valida(): void {
		$this->assertNull(
			MaquinaEstados::problemaEnSecuencia(
				array(
					Fabrica::entrada( '2026-11-13 09:00' ),
					Fabrica::salida( '2026-11-13 13:00', 'refrigerio' ),
					Fabrica::entrada( '2026-11-13 14:00' ),
				)
			)
		);
	}

	public function test_secuencia_con_dos_entradas_seguidas(): void {
		$problema = MaquinaEstados::problemaEnSecuencia( array( Fabrica::entrada( '2026-11-13 09:00' ), Fabrica::entrada( '2026-11-13 10:00' ) ) );

		$this->assertStringContainsString( 'dos entradas seguidas', (string) $problema );
	}

	public function test_secuencia_que_empieza_con_salida(): void {
		$problema = MaquinaEstados::problemaEnSecuencia( array( Fabrica::salida( '2026-11-13 19:30', 'fin_jornada' ) ) );

		$this->assertStringContainsString( 'salida sin entrada', (string) $problema );
	}

	public function test_salida_sin_motivo_no_es_valida(): void {
		$problema = MaquinaEstados::problemaEnSecuencia(
			array( Fabrica::entrada( '2026-11-13 09:00' ), new Marca( TipoMarca::SALIDA, Fabrica::lima( '2026-11-13 19:30' ) ) )
		);

		$this->assertSame( 'Una salida no tiene motivo.', $problema );
	}
}
