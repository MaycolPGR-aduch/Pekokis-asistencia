<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use InvalidArgumentException;
use Pekokis\Asistencia\Nucleo\RedAutorizada;
use PHPUnit\Framework\TestCase;

final class RedAutorizadaTest extends TestCase {
	public function test_ipv4_exacta(): void {
		$red = RedAutorizada::desde( '190.42.10.5' );

		$this->assertTrue( $red->contiene( '190.42.10.5' ) );
		$this->assertFalse( $red->contiene( '190.42.10.6' ) );
		$this->assertSame( '190.42.10.5', $red->texto );
	}

	public function test_ipv4_por_rango(): void {
		$red = RedAutorizada::desde( '190.42.10.0/24' );

		$this->assertTrue( $red->contiene( '190.42.10.200' ) );
		$this->assertFalse( $red->contiene( '190.42.11.1' ) );
	}

	public function test_rango_que_no_cae_en_borde_de_byte(): void {
		$red = RedAutorizada::desde( '10.0.0.0/12' );

		$this->assertTrue( $red->contiene( '10.15.255.255' ) );
		$this->assertFalse( $red->contiene( '10.16.0.0' ) );
	}

	public function test_ipv6_por_prefijo(): void {
		$red = RedAutorizada::desde( '2800:200:e840:1a00::/56' );

		$this->assertTrue( $red->contiene( '2800:200:e840:1a42:abcd::1' ) );
		$this->assertFalse( $red->contiene( '2800:200:e840:1b00::1' ) );
		$this->assertSame( '2800:200:e840:1a00::/56', $red->texto );
	}

	public function test_no_mezcla_ipv4_con_ipv6(): void {
		$this->assertFalse( RedAutorizada::desde( '190.42.10.5' )->contiene( '2800:200::1' ) );
		$this->assertFalse( RedAutorizada::desde( '2800:200::/32' )->contiene( '190.42.10.5' ) );
	}

	public function test_ipv4_mapeada_en_ipv6_cuenta_como_ipv4(): void {
		$this->assertTrue( RedAutorizada::desde( '190.42.10.5' )->contiene( '::ffff:190.42.10.5' ) );
	}

	public function test_texto_invalido_no_contiene_nada(): void {
		$this->assertFalse( RedAutorizada::desde( '190.42.10.5' )->contiene( 'no-es-ip' ) );
		$this->assertFalse( RedAutorizada::desde( '190.42.10.5' )->contiene( '' ) );
	}

	public function test_red_invalida_lanza_excepcion(): void {
		$this->expectException( InvalidArgumentException::class );
		RedAutorizada::desde( '190.42.10.5/33' );
	}

	public function test_red_con_texto_basura_lanza_excepcion(): void {
		$this->expectException( InvalidArgumentException::class );
		RedAutorizada::desde( 'wifi-del-taller' );
	}

	public function test_alguna_contiene(): void {
		$redes = array( RedAutorizada::desde( '190.42.10.5' ), RedAutorizada::desde( '2800:200::/32' ) );

		$this->assertTrue( RedAutorizada::algunaContiene( $redes, '2800:200:1::9' ) );
		$this->assertFalse( RedAutorizada::algunaContiene( $redes, '181.65.1.1' ) );
		$this->assertFalse( RedAutorizada::algunaContiene( array(), '190.42.10.5' ) );
	}

	public function test_detecta_cgnat(): void {
		$this->assertTrue( RedAutorizada::esCgnat( '100.64.0.1' ) );
		$this->assertTrue( RedAutorizada::esCgnat( '100.127.255.254' ) );
		$this->assertFalse( RedAutorizada::esCgnat( '100.128.0.1' ) );
		$this->assertFalse( RedAutorizada::esCgnat( '192.168.1.1' ) );
		$this->assertFalse( RedAutorizada::esCgnat( '2800:200::1' ) );
	}
}
