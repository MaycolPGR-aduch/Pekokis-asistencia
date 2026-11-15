<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use PHPUnit\Framework\TestCase;

final class ValidacionReciboTest extends TestCase {
	public function test_numero_y_monto(): void {
		$ok = ValidacionRecibo::normalizar( array( 'numero' => ' e001-482 ', 'monto' => '1,934.00' ) );

		$this->assertSame( 'E001-482', $ok['numero'] );
		$this->assertSame( 193400, $ok['monto'] );
		$this->assertSame( array(), ValidacionRecibo::errores( $ok ) );

		$mal = ValidacionRecibo::normalizar( array( 'numero' => '482', 'monto' => '0' ) );
		$this->assertSame( array( 'numero', 'monto' ), array_keys( ValidacionRecibo::errores( $mal ) ) );
		$this->assertArrayHasKey( 'numero', ValidacionRecibo::errores( ValidacionRecibo::normalizar( array( 'numero' => 'E01-1', 'monto' => '10' ) ) ) );
	}

	public function test_archivo(): void {
		$this->assertNull( ValidacionRecibo::problemaArchivo( 48000, '%PDF-1.7' ) );
		$this->assertStringContainsString( 'vacío', (string) ValidacionRecibo::problemaArchivo( 0, '' ) );
		$this->assertStringContainsString( 'no es un PDF', (string) ValidacionRecibo::problemaArchivo( 1000, "\x89PNG" ) );
		$this->assertStringContainsString( '5 MB', (string) ValidacionRecibo::problemaArchivo( 6 * 1024 * 1024, '%PDF-1.4' ) );
	}

	public function test_comparacion_con_el_calculo(): void {
		$this->assertTrue( ValidacionRecibo::comparar( 193400, 193400 )['coincide'] );
		$this->assertTrue( ValidacionRecibo::comparar( 193403, 193400 )['coincide'], 'Unos céntimos por redondeo coinciden' );
		$this->assertSame( 'Difiere S/ 12.00 (menos)', ValidacionRecibo::textoComparacion( 192200, 193400 ) );
		$this->assertSame( 'Coincide', ValidacionRecibo::textoComparacion( 193400, 193400 ) );
	}

	public function test_cuando_se_puede_enviar_y_anular(): void {
		$this->assertTrue( ValidacionRecibo::puedeEnviar( null ) );
		$this->assertTrue( ValidacionRecibo::puedeEnviar( ValidacionRecibo::OBSERVADO ), 'Observado: se corrige enviando otro' );
		$this->assertTrue( ValidacionRecibo::puedeEnviar( ValidacionRecibo::ANULADO ) );
		$this->assertFalse( ValidacionRecibo::puedeEnviar( ValidacionRecibo::ENVIADO ) );
		$this->assertFalse( ValidacionRecibo::puedeEnviar( ValidacionRecibo::RECIBIDO ) );
		$this->assertTrue( ValidacionRecibo::puedeAnular( ValidacionRecibo::ENVIADO ) );
		$this->assertFalse( ValidacionRecibo::puedeAnular( ValidacionRecibo::RECIBIDO ) );
	}
}
