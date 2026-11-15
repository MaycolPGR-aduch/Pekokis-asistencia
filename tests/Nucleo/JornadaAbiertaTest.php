<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\JornadaAbierta;
use PHPUnit\Framework\TestCase;

/**
 * Jornadas que pasan la medianoche: siguen abiertas y cuentan en el día en que empezaron.
 */
final class JornadaAbiertaTest extends TestCase {
	public function test_la_jornada_de_ayer_abierta_continua(): void {
		$ayer = array( Fabrica::entrada( '2027-01-14 09:00' ) );

		$this->assertTrue( JornadaAbierta::sigueAbierta( $ayer ) );
		$this->assertSame( '2027-01-14', JornadaAbierta::diaDeTrabajo( '2027-01-15', $ayer, array(), false ), 'Salir a las 10 a. m. del día siguiente cuenta para ayer' );
	}

	public function test_si_ayer_cerro_o_no_vino_es_hoy(): void {
		$cerrada = array( Fabrica::entrada( '2027-01-14 09:00' ), Fabrica::salida( '2027-01-14 19:30', 'fin_jornada' ) );

		$this->assertFalse( JornadaAbierta::sigueAbierta( $cerrada ) );
		$this->assertSame( '2027-01-15', JornadaAbierta::diaDeTrabajo( '2027-01-15', $cerrada, array(), false ) );
		$this->assertSame( '2027-01-15', JornadaAbierta::diaDeTrabajo( '2027-01-15', array(), array(), false ) );
	}

	public function test_hoy_con_marcas_o_con_solicitud_para_ayer_es_hoy(): void {
		$ayer = array( Fabrica::entrada( '2027-01-14 09:00' ) );

		$this->assertSame( '2027-01-15', JornadaAbierta::diaDeTrabajo( '2027-01-15', $ayer, array( Fabrica::entrada( '2027-01-15 09:00' ) ), false ) );
		$this->assertSame( '2027-01-15', JornadaAbierta::diaDeTrabajo( '2027-01-15', $ayer, array(), true ), '"Salí ayer y olvidé marcar" deja empezar el día de hoy' );
	}

	public function test_en_comida_o_fuera_tambien_esta_abierta(): void {
		$this->assertTrue( JornadaAbierta::sigueAbierta( array( Fabrica::entrada( '2027-01-14 09:00' ), Fabrica::salida( '2027-01-14 13:00', 'refrigerio' ) ) ) );
	}

	public function test_jornada_larga(): void {
		$marcas = array( Fabrica::entrada( '2027-01-14 09:00' ), Fabrica::salida( '2027-01-14 13:00', 'refrigerio' ), Fabrica::entrada( '2027-01-14 14:00' ) );

		$this->assertSame( 16 * 60, JornadaAbierta::minutosDesdeLaEntrada( $marcas, Fabrica::lima( '2027-01-15 01:00' ) ) );
		$this->assertFalse( JornadaAbierta::esLarga( $marcas, Fabrica::lima( '2027-01-15 01:00' ), 16 ), '16 h justas no avisan' );
		$this->assertTrue( JornadaAbierta::esLarga( $marcas, Fabrica::lima( '2027-01-15 10:00' ), 16 ), 'Salió a las 10 a. m. del día siguiente' );
		$this->assertFalse( JornadaAbierta::esLarga( $marcas, Fabrica::lima( '2027-01-15 10:00' ), 0 ), 'Con 0 no se avisa' );
	}
}
