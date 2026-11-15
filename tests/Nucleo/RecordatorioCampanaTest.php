<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use Pekokis\Asistencia\Nucleo\RecordatorioCampana;
use PHPUnit\Framework\TestCase;

/**
 * "Aún no marcas tu entrada" (maquetas T9A y G15).
 */
final class RecordatorioCampanaTest extends TestCase {
	private function campana( bool $activo = true ): RecordatorioCampana {
		return RecordatorioCampana::desdeAjustes(
			array( 'campana_activa' => $activo, 'campana_desde' => '2027-01-04', 'campana_hasta' => '2027-03-31', 'campana_hora' => '09:15', 'campana_texto' => '' )
		);
	}

	public function test_sale_en_campana_pasada_la_hora_si_no_marco(): void {
		$c = $this->campana();

		$this->assertTrue( $c->toca( '2027-01-14', '09:15', true, false ) );
		$this->assertSame( RecordatorioCampana::TEXTO, $c->texto, 'Sin texto propio, el de las maquetas' );
	}

	public function test_no_sale_antes_de_la_hora_ni_si_ya_marco(): void {
		$c = $this->campana();

		$this->assertFalse( $c->toca( '2027-01-14', '09:14', true, false ) );
		$this->assertFalse( $c->toca( '2027-01-14', '10:00', true, true ), 'Desaparece al marcar' );
	}

	public function test_no_sale_fuera_de_campana_ni_en_dia_libre_ni_apagado(): void {
		$this->assertFalse( $this->campana()->toca( '2027-04-01', '10:00', true, false ), 'Terminó la campaña' );
		$this->assertFalse( $this->campana()->toca( '2027-01-03', '10:00', true, false ), 'Todavía no empieza' );
		$this->assertFalse( $this->campana()->toca( '2027-01-17', '10:00', false, false ), 'Domingo o feriado' );
		$this->assertFalse( $this->campana( false )->toca( '2027-01-14', '10:00', true, false ), 'Gerencia lo desactivó' );
	}

	public function test_errores_al_guardar(): void {
		$mal = RecordatorioCampana::desdeAjustes( array( 'campana_activa' => true, 'campana_desde' => '2027-03-31', 'campana_hasta' => '2027-01-04', 'campana_hora' => '25:00' ) );

		$this->assertSame( array( 'La campaña termina antes de empezar.' ), $mal->errores() );
		$this->assertSame( '09:15', $mal->hora, 'Una hora inválida vuelve a 09:15' );
		$this->assertSame( array(), $this->campana()->errores() );
	}
}
