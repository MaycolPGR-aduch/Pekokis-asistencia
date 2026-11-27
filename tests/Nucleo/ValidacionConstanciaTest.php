<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\ValidacionConstancia;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use PHPUnit\Framework\TestCase;

/**
 * Constancia de retención de 4.ª o 5.ª y varias áreas por persona (bloque 3 de gerencia).
 */
final class ValidacionConstanciaTest extends TestCase {
	private const HOY = '2026-11-27';

	public function test_datos_de_la_constancia(): void {
		$ok = ValidacionConstancia::normalizar( array( 'tipo' => 'cuarta', 'numero' => '  1609  0012 ', 'vigente_hasta' => '2026-12-31' ) );

		$this->assertSame( array(), ValidacionConstancia::errores( $ok, self::HOY ) );
		$this->assertSame( '1609 0012', $ok['numero'] );

		$mal = ValidacionConstancia::errores( ValidacionConstancia::normalizar( array( 'tipo' => 'sexta', 'vigente_hasta' => '2026-11-26' ) ), self::HOY );
		$this->assertSame( array( 'tipo', 'vigente_hasta' ), array_keys( $mal ) );
		$this->assertStringContainsString( 'venció', $mal['vigente_hasta'] );

		$lejos = ValidacionConstancia::errores( ValidacionConstancia::normalizar( array( 'tipo' => 'quinta', 'vigente_hasta' => '2030-01-01' ) ), self::HOY );
		$this->assertArrayHasKey( 'vigente_hasta', $lejos );
		$this->assertSame( '2026-12-31', ValidacionConstancia::vigenciaPorDefecto( self::HOY ) );
	}

	public function test_vigencia_y_aviso_de_vencimiento(): void {
		$this->assertSame( ValidacionConstancia::VIGENTE, ValidacionConstancia::vigencia( '2027-03-01', self::HOY ) );
		$this->assertSame( ValidacionConstancia::POR_VENCER, ValidacionConstancia::vigencia( '2026-12-27', self::HOY ), '30 días antes ya avisa' );
		$this->assertSame( ValidacionConstancia::VIGENTE, ValidacionConstancia::vigencia( '2026-12-28', self::HOY ) );
		$this->assertSame( ValidacionConstancia::POR_VENCER, ValidacionConstancia::vigencia( self::HOY, self::HOY ) );
		$this->assertSame( ValidacionConstancia::VENCIDA, ValidacionConstancia::vigencia( '2026-11-26', self::HOY ) );

		$this->assertSame( 'Vence en 4 días (01/12/2026)', ValidacionConstancia::textoVigencia( '2026-12-01', self::HOY ) );
		$this->assertSame( 'Vence en 1 día (28/11/2026)', ValidacionConstancia::textoVigencia( '2026-11-28', self::HOY ) );
		$this->assertSame( 'Vence hoy', ValidacionConstancia::textoVigencia( self::HOY, self::HOY ) );
		$this->assertSame( 'Venció el 31/10/2026', ValidacionConstancia::textoVigencia( '2026-10-31', self::HOY ) );
		$this->assertSame( 'Vale hasta el 31/03/2027', ValidacionConstancia::textoVigencia( '2027-03-31', self::HOY ) );
	}

	public function test_se_sube_otra_salvo_que_espere_revision(): void {
		$this->assertTrue( ValidacionConstancia::puedeEnviar( null ) );
		$this->assertTrue( ValidacionConstancia::puedeEnviar( ValidacionRecibo::RECIBIDO ), 'La del año siguiente' );
		$this->assertTrue( ValidacionConstancia::puedeEnviar( ValidacionRecibo::OBSERVADO ) );
		$this->assertFalse( ValidacionConstancia::puedeEnviar( ValidacionRecibo::ENVIADO ) );
	}

	public function test_varias_areas_por_persona(): void {
		$this->assertSame( 'Costura, Estampado', ValidacionTrabajador::unirAreas( array( 'areas' => array( 'Costura', ' Estampado ', 'costura' ) ) ) );
		$this->assertSame( 'Costura, Corte, Empaque', ValidacionTrabajador::unirAreas( array( 'areas' => array( 'Costura' ), 'area_otra' => 'Corte,  Empaque ,' ) ) );
		$this->assertSame( 'Ventas, Taller', ValidacionTrabajador::unirAreas( array( 'area' => 'Ventas ,Taller' ) ), 'También el texto con comas' );
		$this->assertSame( '', ValidacionTrabajador::unirAreas( array() ) );
		$this->assertSame( array( 'Costura', 'Estampado' ), ValidacionTrabajador::areas( 'Costura, Estampado' ) );
		$this->assertSame( array(), ValidacionTrabajador::areas( '' ) );

		$datos = ValidacionTrabajador::normalizar( array( 'dni' => '12345678', 'nombres' => 'A', 'apellidos' => 'B', 'horario_id' => 1, 'areas' => array( 'Costura' ), 'area_otra' => 'Estampado' ) );
		$this->assertSame( 'Costura, Estampado', $datos['area'] );
		$this->assertArrayNotHasKey( 'area', ValidacionTrabajador::errores( $datos ) );

		$muchas = ValidacionTrabajador::normalizar( array( 'area' => implode( ', ', array_map( static fn( int $n ): string => 'Área larga de prueba ' . $n, range( 1, 20 ) ) ) ) );
		$this->assertArrayHasKey( 'area', ValidacionTrabajador::errores( $muchas + array( 'horario_id' => 1 ) ) );
	}
}
