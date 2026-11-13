<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\Base64Url;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use PHPUnit\Framework\TestCase;

final class ValidacionTrabajadorTest extends TestCase {
	private function valido( array $cambios = array() ): array {
		return ValidacionTrabajador::normalizar(
			$cambios + array(
				'dni'        => '45678912',
				'nombres'    => 'Ana  María',
				'apellidos'  => 'Quispe Rojas',
				'horario_id' => 1,
			)
		);
	}

	public function test_datos_correctos_no_tienen_errores(): void {
		$this->assertSame( array(), ValidacionTrabajador::errores( $this->valido() ) );
	}

	public function test_normaliza_espacios_dni_correo_y_telefono(): void {
		$datos = $this->valido(
			array(
				'dni'      => ' 4567-8912 ',
				'correo'   => ' Ana@Pekokis.COM ',
				'telefono' => '+51 987 654 321',
			)
		);

		$this->assertSame( '45678912', $datos['dni'] );
		$this->assertSame( 'Ana María', $datos['nombres'] );
		$this->assertSame( 'ana@pekokis.com', $datos['correo'] );
		$this->assertSame( '+51987654321', $datos['telefono'] );
	}

	public function test_acepta_carne_de_extranjeria(): void {
		$this->assertArrayNotHasKey( 'dni', ValidacionTrabajador::errores( $this->valido( array( 'dni' => '001234567' ) ) ) );
	}

	public function test_rechaza_dni_invalido(): void {
		$this->assertArrayHasKey( 'dni', ValidacionTrabajador::errores( $this->valido( array( 'dni' => '1234567' ) ) ) );
		$this->assertArrayHasKey( 'dni', ValidacionTrabajador::errores( $this->valido( array( 'dni' => '' ) ) ) );
	}

	public function test_campos_obligatorios_y_formatos(): void {
		$errores = ValidacionTrabajador::errores(
			$this->valido(
				array(
					'nombres'       => '  ',
					'apellidos'     => '',
					'correo'        => 'no-es-correo',
					'telefono'      => '12',
					'fecha_ingreso' => '2026-02-30',
					'horario_id'    => 0,
				)
			)
		);

		$this->assertSame(
			array( 'nombres', 'apellidos', 'correo', 'telefono', 'fecha_ingreso', 'horario_id' ),
			array_keys( $errores )
		);
	}

	public function test_base64url_ida_y_vuelta(): void {
		$binario = random_bytes( 33 );

		$this->assertSame( $binario, Base64Url::decodificar( Base64Url::codificar( $binario ) ) );
		$this->assertNull( Base64Url::decodificar( 'no+válido' ) );
		$this->assertNull( Base64Url::decodificar( 'a' ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{32}$/', Base64Url::aleatorio( 24 ) );
	}
}
