<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\CategoriaMotivo;
use Pekokis\Asistencia\Nucleo\ContextoPresencia;
use Pekokis\Asistencia\Nucleo\ModoPresencia;
use Pekokis\Asistencia\Nucleo\Motivo;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\SolicitudMarca;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\Nucleo\ValidadorMarca;
use PHPUnit\Framework\TestCase;

final class ValidadorMarcaTest extends TestCase {
	private function wifi(): ContextoPresencia {
		return new ContextoPresencia( true );
	}

	private function qr(): ContextoPresencia {
		return new ContextoPresencia( false, 1 );
	}

	private function ambos(): ContextoPresencia {
		return new ContextoPresencia( true, 1 );
	}

	private function nada(): ContextoPresencia {
		return new ContextoPresencia( false );
	}

	private function entrada(): SolicitudMarca {
		return new SolicitudMarca( TipoMarca::ENTRADA );
	}

	private function salida( string $motivo ): SolicitudMarca {
		return new SolicitudMarca( TipoMarca::SALIDA, Fabrica::motivo( $motivo ) );
	}

	public function test_entrada_con_wifi_se_acepta(): void {
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( array(), $this->entrada(), $this->wifi(), Fabrica::lima( '2026-11-13 08:58' ) );

		$this->assertTrue( $resultado->esAceptada() );
		$this->assertSame( PruebaPresencia::WIFI, $resultado->prueba );
		$this->assertFalse( $resultado->requiereRevision );
	}

	public function test_modo_wifi_o_qr(): void {
		$validador = new ValidadorMarca( ModoPresencia::WIFI_O_QR );
		$ahora     = Fabrica::lima( '2026-11-13 08:58' );

		$this->assertSame( PruebaPresencia::QR, $validador->decidir( array(), $this->entrada(), $this->qr(), $ahora )->prueba );
		$this->assertSame( PruebaPresencia::WIFI_QR, $validador->decidir( array(), $this->entrada(), $this->ambos(), $ahora )->prueba );

		$sin = $validador->decidir( array(), $this->entrada(), $this->nada(), $ahora );
		$this->assertTrue( $sin->esRechazada() );
		$this->assertSame( 'sin_presencia', $sin->codigo );
		$this->assertStringContainsString( 'Escanea el QR', $sin->mensaje );
	}

	public function test_modo_wifi_y_qr_exige_las_dos_cosas(): void {
		$validador = new ValidadorMarca( ModoPresencia::WIFI_Y_QR );
		$ahora     = Fabrica::lima( '2026-11-13 08:58' );

		$this->assertTrue( $validador->decidir( array(), $this->entrada(), $this->wifi(), $ahora )->esRechazada() );
		$this->assertTrue( $validador->decidir( array(), $this->entrada(), $this->qr(), $ahora )->esRechazada() );
		$this->assertSame( PruebaPresencia::WIFI_QR, $validador->decidir( array(), $this->entrada(), $this->ambos(), $ahora )->prueba );
	}

	public function test_modo_solo_qr_ignora_el_wifi(): void {
		$validador = new ValidadorMarca( ModoPresencia::SOLO_QR );
		$ahora     = Fabrica::lima( '2026-11-13 08:58' );

		$this->assertTrue( $validador->decidir( array(), $this->entrada(), $this->wifi(), $ahora )->esRechazada() );
		$this->assertSame( PruebaPresencia::QR, $validador->decidir( array(), $this->entrada(), $this->ambos(), $ahora )->prueba );
	}

	public function test_modo_solo_wifi_ignora_el_qr(): void {
		$validador = new ValidadorMarca( ModoPresencia::SOLO_WIFI );
		$ahora     = Fabrica::lima( '2026-11-13 08:58' );

		$resultado = $validador->decidir( array(), $this->entrada(), $this->qr(), $ahora );
		$this->assertTrue( $resultado->esRechazada() );
		$this->assertSame( 'Conéctate al WiFi del taller para marcar.', $resultado->mensaje );
		$this->assertSame( PruebaPresencia::WIFI, $validador->decidir( array(), $this->entrada(), $this->ambos(), $ahora )->prueba );
	}

	public function test_salida_sin_motivo_se_rechaza(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 08:58' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, new SolicitudMarca( TipoMarca::SALIDA ), $this->wifi(), Fabrica::lima( '2026-11-13 13:00' ) );

		$this->assertSame( 'motivo_requerido', $resultado->codigo );
	}

	public function test_entrada_con_motivo_se_rechaza(): void {
		$solicitud = new SolicitudMarca( TipoMarca::ENTRADA, Fabrica::motivo( 'refrigerio' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( array(), $solicitud, $this->wifi(), Fabrica::lima( '2026-11-13 08:58' ) );

		$this->assertSame( 'motivo_no_aplica', $resultado->codigo );
	}

	public function test_motivo_inactivo_se_rechaza(): void {
		$inactivo  = new Motivo( 'viejo', 'Motivo viejo', CategoriaMotivo::TEMPORAL, false, false, false, false );
		$dia       = array( Fabrica::entrada( '2026-11-13 08:58' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, new SolicitudMarca( TipoMarca::SALIDA, $inactivo ), $this->wifi(), Fabrica::lima( '2026-11-13 10:00' ) );

		$this->assertSame( 'motivo_inactivo', $resultado->codigo );
	}

	public function test_salida_estando_fuera_se_rechaza(): void {
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( array(), $this->salida( 'fin_jornada' ), $this->wifi(), Fabrica::lima( '2026-11-13 18:00' ) );

		$this->assertSame( 'accion_invalida', $resultado->codigo );
		$this->assertSame( 'Esa marca no corresponde: estás fuera.', $resultado->mensaje );
	}

	public function test_doble_toque_devuelve_la_marca_existente(): void {
		$primera   = Fabrica::entrada( '2026-11-13 08:58:00', 7 );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( array( $primera ), $this->entrada(), $this->wifi(), Fabrica::lima( '2026-11-13 08:58:40' ) );

		$this->assertTrue( $resultado->esRebote() );
		$this->assertSame( $primera, $resultado->marcaExistente );
	}

	public function test_doble_toque_tambien_aplica_sin_presencia(): void {
		$primera   = Fabrica::entrada( '2026-11-13 08:58:00', 7 );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( array( $primera ), $this->entrada(), $this->nada(), Fabrica::lima( '2026-11-13 08:59:00' ) );

		$this->assertTrue( $resultado->esRebote() );
	}

	public function test_segunda_entrada_pasado_el_antirrebote_es_invalida(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 08:58:00' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->entrada(), $this->wifi(), Fabrica::lima( '2026-11-13 09:00:00' ) );

		$this->assertSame( 'accion_invalida', $resultado->codigo );
		$this->assertSame( 'Esa marca no corresponde: estás trabajando.', $resultado->mensaje );
	}

	public function test_salida_con_otro_motivo_no_es_rebote(): void {
		$dia       = array(
			Fabrica::entrada( '2026-11-13 08:58' ),
			Fabrica::salida( '2026-11-13 13:00:00', 'refrigerio' ),
		);
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->salida( 'encargo' ), $this->wifi(), Fabrica::lima( '2026-11-13 13:00:30' ) );

		$this->assertSame( 'accion_invalida', $resultado->codigo );
	}

	public function test_emergencia_se_acepta_sin_presencia_y_queda_para_revision(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 09:00' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->salida( 'emergencia' ), $this->nada(), Fabrica::lima( '2026-11-13 11:20' ) );

		$this->assertTrue( $resultado->esAceptada() );
		$this->assertSame( PruebaPresencia::SIN_PRESENCIA, $resultado->prueba );
		$this->assertTrue( $resultado->requiereRevision );
	}

	public function test_emergencia_en_el_taller_guarda_la_prueba_real(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 09:00' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->salida( 'emergencia' ), $this->wifi(), Fabrica::lima( '2026-11-13 11:20' ) );

		$this->assertSame( PruebaPresencia::WIFI, $resultado->prueba );
		$this->assertTrue( $resultado->requiereRevision, 'La emergencia se justifica después.' );
	}

	public function test_otras_salidas_sin_presencia_se_rechazan(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 09:00' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->salida( 'fin_jornada' ), $this->nada(), Fabrica::lima( '2026-11-13 19:31' ) );

		$this->assertSame( 'sin_presencia', $resultado->codigo );
	}

	public function test_salida_personal_queda_para_aprobacion(): void {
		$dia       = array( Fabrica::entrada( '2026-11-13 09:00' ) );
		$resultado = ( new ValidadorMarca( ModoPresencia::WIFI_O_QR ) )
			->decidir( $dia, $this->salida( 'personal' ), $this->wifi(), Fabrica::lima( '2026-11-13 15:30' ) );

		$this->assertTrue( $resultado->esAceptada() );
		$this->assertTrue( $resultado->requiereRevision );
	}

	public function test_refrigerio_y_regreso_no_requieren_revision(): void {
		$validador = new ValidadorMarca( ModoPresencia::WIFI_O_QR );
		$dia       = array( Fabrica::entrada( '2026-11-13 09:00' ) );

		$salida = $validador->decidir( $dia, $this->salida( 'refrigerio' ), $this->wifi(), Fabrica::lima( '2026-11-13 13:00' ) );
		$this->assertTrue( $salida->esAceptada() );
		$this->assertFalse( $salida->requiereRevision );

		$dia[]   = Fabrica::salida( '2026-11-13 13:00', 'refrigerio' );
		$regreso = $validador->decidir( $dia, $this->entrada(), $this->qr(), Fabrica::lima( '2026-11-13 13:48' ) );
		$this->assertTrue( $regreso->esAceptada() );
		$this->assertSame( PruebaPresencia::QR, $regreso->prueba );
	}
}
