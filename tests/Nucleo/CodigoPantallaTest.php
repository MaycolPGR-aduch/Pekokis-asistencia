<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use InvalidArgumentException;
use Pekokis\Asistencia\Nucleo\CodigoPantalla;
use PHPUnit\Framework\TestCase;

final class CodigoPantallaTest extends TestCase {
	private const AHORA = 1794585600; // múltiplo de 30: inicio exacto de una ventana

	private function codigos(): CodigoPantalla {
		return new CodigoPantalla( Fabrica::SECRETO );
	}

	public function test_token_valido_en_su_ventana_y_en_la_siguiente(): void {
		$codigos = $this->codigos();
		$token   = $codigos->token( 3, CodigoPantalla::ventana( self::AHORA ) );

		$this->assertSame( 3, $codigos->validarToken( $token, self::AHORA ) );
		$this->assertSame( 3, $codigos->validarToken( $token, self::AHORA + 29 ) );
		$this->assertSame( 3, $codigos->validarToken( $token, self::AHORA + 59 ) );
	}

	public function test_token_vencido_se_rechaza(): void {
		$codigos = $this->codigos();
		$token   = $codigos->token( 3, CodigoPantalla::ventana( self::AHORA ) );

		$this->assertNull( $codigos->validarToken( $token, self::AHORA + 60 ) );
	}

	public function test_token_del_futuro_se_rechaza(): void {
		$codigos = $this->codigos();
		$token   = $codigos->token( 3, CodigoPantalla::ventana( self::AHORA ) + 1 );

		$this->assertNull( $codigos->validarToken( $token, self::AHORA ) );
	}

	public function test_token_alterado_se_rechaza(): void {
		$codigos = $this->codigos();
		$ventana = CodigoPantalla::ventana( self::AHORA );
		$token   = $codigos->token( 3, $ventana );

		// Otra pantalla con la firma de la pantalla 3.
		$alterado = '4' . substr( $token, 1 );
		$this->assertNull( $codigos->validarToken( $alterado, self::AHORA ) );

		// Firma de otro secreto.
		$ajeno = ( new CodigoPantalla( str_repeat( 'z', 64 ) ) )->token( 3, $ventana );
		$this->assertNull( $codigos->validarToken( $ajeno, self::AHORA ) );
	}

	public function test_token_mal_formado_se_rechaza(): void {
		$codigos = $this->codigos();

		$this->assertNull( $codigos->validarToken( '', self::AHORA ) );
		$this->assertNull( $codigos->validarToken( '3.abc.xyz', self::AHORA ) );
		$this->assertNull( $codigos->validarToken( "3.1.<script>", self::AHORA ) );
	}

	public function test_codigo_de_seis_digitos(): void {
		$codigo = $this->codigos()->codigo( 3, CodigoPantalla::ventana( self::AHORA ) );

		$this->assertMatchesRegularExpression( '/^\d{6}$/', $codigo );
	}

	public function test_codigo_valido_identifica_la_pantalla(): void {
		$codigos = $this->codigos();
		$codigo  = $codigos->codigo( 2, CodigoPantalla::ventana( self::AHORA ) );

		$this->assertSame( 2, $codigos->validarCodigo( $codigo, array( 1, 2 ), self::AHORA + 45 ) );
		$this->assertNull( $codigos->validarCodigo( $codigo, array( 1, 2 ), self::AHORA + 60 ), 'Vencido' );
		$this->assertNull( $codigos->validarCodigo( $codigo, array( 1 ), self::AHORA ), 'Pantalla inactiva' );
	}

	public function test_codigo_mal_formado_se_rechaza(): void {
		$codigos = $this->codigos();

		$this->assertNull( $codigos->validarCodigo( '12345', array( 1 ), self::AHORA ) );
		$this->assertNull( $codigos->validarCodigo( '12345a', array( 1 ), self::AHORA ) );
	}

	public function test_segundos_restantes(): void {
		$this->assertSame( 30, CodigoPantalla::segundosRestantes( self::AHORA ) );
		$this->assertSame( 1, CodigoPantalla::segundosRestantes( self::AHORA + 29 ) );
	}

	public function test_secreto_corto_no_se_acepta(): void {
		$this->expectException( InvalidArgumentException::class );
		new CodigoPantalla( 'corto' );
	}
}
