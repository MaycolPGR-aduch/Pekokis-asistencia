<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\PasosDelDia as P;
use PHPUnit\Framework\TestCase;

final class PasosDelDiaTest extends TestCase {
	public function test_dia_completo(): void {
		$e  = Fabrica::entrada( '2027-01-14 09:00' );
		$sc = Fabrica::salida( '2027-01-14 13:00', 'refrigerio' );
		$vc = Fabrica::entrada( '2027-01-14 14:00' );
		$s  = Fabrica::salida( '2027-01-14 19:30', 'fin_jornada' );

		$this->assertSame( array( P::ENTRADA => $e, P::INICIO_COMIDA => $sc, P::FIN_COMIDA => $vc, P::SALIDA => $s ), P::de( array( $s, $vc, $sc, $e ) ) );
		$this->assertSame( array(), P::otras( array( $e, $sc, $vc, $s ) ) );
	}

	public function test_sin_salida_ni_comida(): void {
		$e = Fabrica::entrada( '2027-01-14 09:00' );

		$this->assertSame( array( P::ENTRADA => $e, P::INICIO_COMIDA => null, P::FIN_COMIDA => null, P::SALIDA => null ), P::de( array( $e ) ) );
	}

	public function test_la_salida_temporal_no_es_un_paso(): void {
		$e  = Fabrica::entrada( '2027-01-14 09:00' );
		$st = Fabrica::salida( '2027-01-14 11:00', 'encargo' );
		$r  = Fabrica::entrada( '2027-01-14 11:30' );
		$s  = Fabrica::salida( '2027-01-14 19:30', 'fin_jornada' );

		$pasos = P::de( array( $e, $st, $r, $s ) );
		$this->assertSame( $e, $pasos[ P::ENTRADA ] );
		$this->assertNull( $pasos[ P::FIN_COMIDA ], 'El regreso del encargo no es "vuelta de comer"' );
		$this->assertSame( $s, $pasos[ P::SALIDA ] );
		$this->assertSame( array( $st, $r ), P::otras( array( $e, $st, $r, $s ) ) );
	}
}
