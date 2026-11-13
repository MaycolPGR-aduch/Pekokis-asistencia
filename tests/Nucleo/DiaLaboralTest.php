<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\DiaLaboral;
use PHPUnit\Framework\TestCase;

final class DiaLaboralTest extends TestCase {
	private function dias(): DiaLaboral {
		return new DiaLaboral( new DateTimeZone( 'America/Lima' ), 4 );
	}

	public function test_dia_normal(): void {
		$this->assertSame( '2026-11-13', $this->dias()->de( Fabrica::lima( '2026-11-13 08:58' ) ) );
		$this->assertSame( '2026-11-13', $this->dias()->de( Fabrica::lima( '2026-11-13 23:59' ) ) );
	}

	public function test_antes_de_la_hora_de_corte_es_el_dia_anterior(): void {
		$this->assertSame( '2026-11-12', $this->dias()->de( Fabrica::lima( '2026-11-13 03:59' ) ) );
		$this->assertSame( '2026-11-13', $this->dias()->de( Fabrica::lima( '2026-11-13 04:00' ) ) );
	}

	public function test_usa_la_hora_de_lima_y_no_la_utc(): void {
		// 01:30 UTC del 14 son las 20:30 del 13 en Lima.
		$momento = new DateTimeImmutable( '2026-11-14 01:30:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-11-13', $this->dias()->de( $momento ) );
	}

	public function test_rango_utc_del_dia(): void {
		[ $inicio, $fin ] = $this->dias()->rangoUtc( '2026-11-13' );

		$this->assertSame( '2026-11-13 09:00:00', $inicio->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-11-14 09:00:00', $fin->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', $inicio->getTimezone()->getName() );
	}
}
