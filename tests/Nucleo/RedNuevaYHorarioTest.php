<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\DeteccionRedNueva;
use Pekokis\Asistencia\Nucleo\RedAutorizada;
use Pekokis\Asistencia\Nucleo\ValidacionHorario;
use PHPUnit\Framework\TestCase;

final class RedNuevaYHorarioTest extends TestCase {
	private function qr( string $ip, int $trabajador ): array {
		return array( 'ip' => $ip, 'trabajador' => $trabajador );
	}

	public function test_red_nueva_con_varias_personas(): void {
		$marcas = array( $this->qr( '181.65.1.10', 1 ), $this->qr( '181.65.1.10', 2 ), $this->qr( '181.65.1.10', 1 ) );
		$c      = DeteccionRedNueva::candidatas( $marcas, array( RedAutorizada::desde( '190.42.10.5' ) ) );

		$this->assertSame( array( array( 'red' => '181.65.1.10', 'marcas' => 3, 'trabajadores' => 2, 'cgnat' => false ) ), $c );
	}

	public function test_una_sola_persona_no_alcanza(): void {
		$marcas = array( $this->qr( '181.65.1.10', 1 ), $this->qr( '181.65.1.10', 1 ), $this->qr( '181.65.1.10', 1 ) );

		$this->assertSame( array(), DeteccionRedNueva::candidatas( $marcas, array() ) );
	}

	public function test_pocas_marcas_no_alcanzan(): void {
		$this->assertSame( array(), DeteccionRedNueva::candidatas( array( $this->qr( '181.65.1.10', 1 ), $this->qr( '181.65.1.10', 2 ) ), array() ) );
	}

	public function test_las_redes_autorizadas_no_se_sugieren(): void {
		$marcas = array( $this->qr( '190.42.10.5', 1 ), $this->qr( '190.42.10.5', 2 ), $this->qr( '190.42.10.5', 3 ) );

		$this->assertSame( array(), DeteccionRedNueva::candidatas( $marcas, array( RedAutorizada::desde( '190.42.10.5' ) ) ) );
	}

	public function test_ipv6_se_agrupa_por_prefijo_64(): void {
		$marcas = array(
			$this->qr( '2800:200:e840:1a42:1111::1', 1 ),
			$this->qr( '2800:200:e840:1a42:2222::9', 2 ),
			$this->qr( '2800:200:e840:1a42:3333::5', 3 ),
		);
		$c      = DeteccionRedNueva::candidatas( $marcas, array() );

		$this->assertSame( '2800:200:e840:1a42::/64', $c[0]['red'] );
		$this->assertSame( 3, $c[0]['trabajadores'] );
	}

	public function test_avisa_si_es_cgnat(): void {
		$marcas = array( $this->qr( '100.70.1.2', 1 ), $this->qr( '100.70.1.2', 2 ), $this->qr( '100.70.1.2', 2 ) );

		$this->assertTrue( DeteccionRedNueva::candidatas( $marcas, array() )[0]['cgnat'] );
	}

	public function test_la_red_se_guarda_como_direccion_de_red(): void {
		$this->assertSame( '190.42.10.0/24', RedAutorizada::desde( '190.42.10.77/24' )->texto );
		$this->assertSame( '190.42.10.77', RedAutorizada::desde( '190.42.10.77' )->texto );
	}

	private function horario( array $cambios = array() ): array {
		$dias = array();
		for ( $n = 1; $n <= 7; $n++ ) {
			$dias[ $n ] = $n <= 5 ? array( 'laborable' => '1', 'entrada' => '9:00', 'salida' => '19:30' ) : array();
		}

		return ValidacionHorario::normalizar( $cambios + array( 'nombre' => ' Horario  base ', 'tolerancia_min' => '5', 'refrigerio_min' => '60', 'dias' => $dias ) );
	}

	public function test_horario_valido_y_normalizado(): void {
		$h = $this->horario();

		$this->assertSame( array(), ValidacionHorario::errores( $h ) );
		$this->assertSame( 'Horario base', $h['nombre'] );
		$this->assertSame( '09:00:00', $h['dias'][1]['entrada'] );
		$this->assertNull( $h['dias'][6]['entrada'] );
		$this->assertSame( 47.5, ValidacionHorario::horasSemana( $h ) );
	}

	public function test_horario_con_errores(): void {
		$dias    = array( 1 => array( 'laborable' => '1', 'entrada' => '19:00', 'salida' => '09:00' ), 2 => array( 'laborable' => '1', 'entrada' => '', 'salida' => '18:00' ) );
		$errores = ValidacionHorario::errores( $this->horario( array( 'nombre' => '', 'tolerancia_min' => 90, 'refrigerio_min' => 30, 'dias' => $dias ) ) );

		$this->assertSame( array( 'nombre', 'tolerancia_min', 'refrigerio_min', 'dia_1', 'dia_2' ), array_keys( $errores ) );
		$this->assertStringContainsString( 'después de la entrada', $errores['dia_1'] );
	}

	public function test_horario_sin_dias_laborables(): void {
		$this->assertArrayHasKey( 'dias', ValidacionHorario::errores( $this->horario( array( 'dias' => array() ) ) ) );
	}
}
