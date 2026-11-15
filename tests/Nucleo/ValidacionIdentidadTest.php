<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\ValidacionIdentidad as V;
use PHPUnit\Framework\TestCase;

final class ValidacionIdentidadTest extends TestCase {
	public function test_pin_valido(): void {
		$this->assertNull( V::errorPin( '482915' ) );
		$this->assertSame( '482915', V::normalizarPin( '482 915' ) );
	}

	public function test_pin_que_no_sirve(): void {
		$this->assertSame( 'El PIN debe tener 6 números.', V::errorPin( '12345' ) );
		$this->assertSame( 'El PIN debe tener 6 números.', V::errorPin( '12345a' ) );
		$this->assertNotNull( V::errorPin( '111111' ), 'Mismo número' );
		$this->assertNotNull( V::errorPin( '123456' ), 'Escalera' );
		$this->assertNotNull( V::errorPin( '987654' ), 'Escalera al revés' );
		$this->assertNull( V::errorPin( '4829', 4 ), 'El largo se configura' );
	}

	public function test_cumpleanos(): void {
		$this->assertSame( '03-07', V::cumpleanos( 7, 3 ) );
		$this->assertSame( '02-29', V::cumpleanos( '29', '2' ), 'El 29 de febrero existe' );
		$this->assertSame( '', V::cumpleanos( 31, 4 ), 'El 31 de abril no existe' );
		$this->assertSame( '', V::cumpleanos( '', '' ) );
	}

	public function test_confirmar_que_es_la_persona(): void {
		$this->assertTrue( V::esLaPersona( '45678912', '03-07', '45.678.912', '03-07' ) );
		$this->assertFalse( V::esLaPersona( '45678912', '03-07', '45678912', '03-08' ), 'Otro cumpleaños' );
		$this->assertFalse( V::esLaPersona( '45678912', '03-07', '45678913', '03-07' ), 'Otro DNI' );
		$this->assertFalse( V::esLaPersona( '45678912', '', '45678912', '' ), 'Sin cumpleaños en el perfil no se puede confirmar' );
	}
}
