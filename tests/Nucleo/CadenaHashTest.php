<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\CadenaHash;
use PHPUnit\Framework\TestCase;

final class CadenaHashTest extends TestCase {
	/** @return array<int, array{hash_anterior: string, hash: string, datos: array<string, mixed>}> */
	private function cadena( array ...$datos ): array {
		$filas    = array();
		$anterior = CadenaHash::GENESIS;

		foreach ( $datos as $fila ) {
			$hash     = CadenaHash::calcular( $anterior, $fila );
			$filas[]  = array( 'hash_anterior' => $anterior, 'hash' => $hash, 'datos' => $fila );
			$anterior = $hash;
		}

		return $filas;
	}

	public function test_el_orden_de_las_claves_no_cambia_el_hash(): void {
		$this->assertSame(
			CadenaHash::calcular( CadenaHash::GENESIS, array( 'tipo' => 'entrada', 'trabajador_id' => 4 ) ),
			CadenaHash::calcular( CadenaHash::GENESIS, array( 'trabajador_id' => 4, 'tipo' => 'entrada' ) )
		);
	}

	public function test_numeros_y_textos_de_mysql_dan_el_mismo_hash(): void {
		$recienArmada = array( 'trabajador_id' => 4, 'requiere_revision' => false, 'motivo_clave' => null );
		$leidaDeMysql = array( 'trabajador_id' => '4', 'requiere_revision' => '0', 'motivo_clave' => null );

		$this->assertSame(
			CadenaHash::calcular( CadenaHash::GENESIS, $recienArmada ),
			CadenaHash::calcular( CadenaHash::GENESIS, $leidaDeMysql )
		);
	}

	public function test_null_y_texto_vacio_son_distintos(): void {
		$this->assertNotSame(
			CadenaHash::calcular( CadenaHash::GENESIS, array( 'motivo_clave' => null ) ),
			CadenaHash::calcular( CadenaHash::GENESIS, array( 'motivo_clave' => '' ) )
		);
	}

	public function test_cadena_integra(): void {
		$filas = $this->cadena(
			array( 'tipo' => 'entrada', 'marcado_utc' => '2026-11-13 13:58:00' ),
			array( 'tipo' => 'salida', 'marcado_utc' => '2026-11-13 18:00:00' ),
			array( 'tipo' => 'entrada', 'marcado_utc' => '2026-11-13 18:48:00' )
		);

		$this->assertNull( CadenaHash::primeraRota( $filas ) );
	}

	public function test_detecta_una_fila_editada(): void {
		$filas = $this->cadena(
			array( 'tipo' => 'entrada', 'marcado_utc' => '2026-11-13 14:20:00' ),
			array( 'tipo' => 'salida', 'marcado_utc' => '2026-11-13 18:00:00' )
		);

		// Alguien cambia la hora de llegada directo en la base de datos.
		$filas[0]['datos']['marcado_utc'] = '2026-11-13 13:58:00';

		$this->assertSame( 0, CadenaHash::primeraRota( $filas ) );
	}

	public function test_detecta_una_fila_borrada(): void {
		$filas = $this->cadena(
			array( 'tipo' => 'entrada' ),
			array( 'tipo' => 'salida' ),
			array( 'tipo' => 'entrada' )
		);

		unset( $filas[1] );

		$this->assertSame( 1, CadenaHash::primeraRota( $filas ) );
	}
}
