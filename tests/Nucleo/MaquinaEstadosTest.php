<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\Accion;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\MaquinaEstados;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use PHPUnit\Framework\TestCase;

final class MaquinaEstadosTest extends TestCase {
	public function test_sin_marcas_esta_fuera(): void {
		$this->assertSame( Estado::FUERA, MaquinaEstados::estadoActual( array() ) );
	}

	public function test_el_estado_sale_de_la_ultima_marca(): void {
		$dia = array( Fabrica::entrada( '2026-11-13 08:58' ) );
		$this->assertSame( Estado::TRABAJANDO, MaquinaEstados::estadoActual( $dia ) );

		$dia[] = Fabrica::salida( '2026-11-13 13:00', 'refrigerio' );
		$this->assertSame( Estado::REFRIGERIO, MaquinaEstados::estadoActual( $dia ) );

		$dia[] = Fabrica::entrada( '2026-11-13 13:48' );
		$dia[] = Fabrica::salida( '2026-11-13 15:30', 'personal' );
		$this->assertSame( Estado::FUERA_TEMPORAL, MaquinaEstados::estadoActual( $dia ) );

		$dia[] = Fabrica::entrada( '2026-11-13 16:05' );
		$dia[] = Fabrica::salida( '2026-11-13 18:02', 'fin_jornada' );
		$this->assertSame( Estado::FUERA, MaquinaEstados::estadoActual( $dia ) );
	}

	public function test_ordena_las_marcas_aunque_lleguen_desordenadas(): void {
		$dia = array(
			Fabrica::salida( '2026-11-13 13:00', 'refrigerio' ),
			Fabrica::entrada( '2026-11-13 08:58' ),
		);

		$this->assertSame( Estado::REFRIGERIO, MaquinaEstados::estadoActual( $dia ) );
	}

	public function test_a_igual_hora_manda_el_id_de_insercion(): void {
		$dia = array(
			Fabrica::salida( '2026-11-13 13:00', 'refrigerio', 2 ),
			Fabrica::entrada( '2026-11-13 13:00', 1 ),
		);

		$this->assertSame( Estado::REFRIGERIO, MaquinaEstados::estadoActual( $dia ) );
	}

	public function test_emergencia_sin_regreso_deja_fuera_temporalmente(): void {
		$dia = array(
			Fabrica::entrada( '2026-11-13 09:00' ),
			Fabrica::salida( '2026-11-13 11:20', 'emergencia' ),
		);

		$this->assertSame( Estado::FUERA_TEMPORAL, MaquinaEstados::estadoActual( $dia ) );
		$this->assertSame( array( Accion::REGRESO ), MaquinaEstados::acciones( Estado::FUERA_TEMPORAL ) );
	}

	public function test_transiciones_permitidas(): void {
		$this->assertTrue( MaquinaEstados::permite( Estado::FUERA, TipoMarca::ENTRADA ) );
		$this->assertTrue( MaquinaEstados::permite( Estado::REFRIGERIO, TipoMarca::ENTRADA ) );
		$this->assertTrue( MaquinaEstados::permite( Estado::FUERA_TEMPORAL, TipoMarca::ENTRADA ) );
		$this->assertFalse( MaquinaEstados::permite( Estado::TRABAJANDO, TipoMarca::ENTRADA ) );

		$this->assertTrue( MaquinaEstados::permite( Estado::TRABAJANDO, TipoMarca::SALIDA ) );
		$this->assertFalse( MaquinaEstados::permite( Estado::FUERA, TipoMarca::SALIDA ) );
		$this->assertFalse( MaquinaEstados::permite( Estado::REFRIGERIO, TipoMarca::SALIDA ) );
	}

	public function test_botones_por_estado(): void {
		$this->assertSame( array( Accion::ENTRADA ), MaquinaEstados::acciones( Estado::FUERA ) );
		$this->assertSame( array( Accion::REGRESO ), MaquinaEstados::acciones( Estado::REFRIGERIO ) );
		$this->assertSame(
			array( Accion::SALIDA_REFRIGERIO, Accion::SALIDA_TEMPORAL, Accion::FIN_JORNADA ),
			MaquinaEstados::acciones( Estado::TRABAJANDO )
		);
	}
}
