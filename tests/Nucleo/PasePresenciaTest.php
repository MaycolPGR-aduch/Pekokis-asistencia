<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\PasePresencia;
use PHPUnit\Framework\TestCase;

final class PasePresenciaTest extends TestCase {
	private const AHORA = 1794585600;

	public function test_pase_vigente_devuelve_la_pantalla(): void {
		$pases = new PasePresencia( Fabrica::SECRETO, 180 );
		$pase  = $pases->emitir( 1, self::AHORA );

		$this->assertSame( 1, $pases->validar( $pase, self::AHORA ) );
		$this->assertSame( 1, $pases->validar( $pase, self::AHORA + 180 ) );
	}

	public function test_pase_vencido_se_rechaza(): void {
		$pases = new PasePresencia( Fabrica::SECRETO, 180 );
		$pase  = $pases->emitir( 1, self::AHORA );

		$this->assertNull( $pases->validar( $pase, self::AHORA + 181 ) );
	}

	public function test_pase_alterado_se_rechaza(): void {
		$pases = new PasePresencia( Fabrica::SECRETO, 180 );
		$pase  = $pases->emitir( 1, self::AHORA );

		// Alargar el vencimiento sin volver a firmar.
		[ $pantalla, $vence, $firma ] = explode( '.', $pase );
		$alargado                     = $pantalla . '.' . ( (int) $vence + 3600 ) . '.' . $firma;

		$this->assertNull( $pases->validar( $alargado, self::AHORA + 600 ) );
		$this->assertNull( ( new PasePresencia( str_repeat( 'z', 64 ) ) )->validar( $pase, self::AHORA ) );
		$this->assertNull( $pases->validar( 'basura', self::AHORA ) );
	}
}
