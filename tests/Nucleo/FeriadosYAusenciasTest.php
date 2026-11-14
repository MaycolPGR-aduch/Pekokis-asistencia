<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\FeriadosPeru;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\ValidacionAusencia;
use PHPUnit\Framework\TestCase;

final class FeriadosYAusenciasTest extends TestCase {
	public function test_domingo_de_pascua_conocido(): void {
		$this->assertSame( '2024-03-31', FeriadosPeru::domingoDePascua( 2024 )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-04-05', FeriadosPeru::domingoDePascua( 2026 )->format( 'Y-m-d' ) );
		$this->assertSame( '2027-03-28', FeriadosPeru::domingoDePascua( 2027 )->format( 'Y-m-d' ) );
	}

	public function test_feriados_del_anio(): void {
		$f = FeriadosPeru::del( 2027 );

		$this->assertCount( 16, $f );
		$this->assertSame( 'Jueves Santo', $f['2027-03-25'] );
		$this->assertSame( 'Viernes Santo', $f['2027-03-26'] );
		$this->assertSame( 'Navidad', $f['2027-12-25'] );
		$this->assertSame( '2027-01-01', array_key_first( $f ), 'En orden de fecha' );
	}

	private function ausencia( array $cambios = array() ): array {
		return ValidacionAusencia::normalizar( $cambios + array( 'trabajador_id' => 3, 'tipo' => 'vacaciones', 'desde' => '2026-12-14', 'hasta' => '2026-12-27', 'nota' => '  Vacaciones  de fin de año ' ) );
	}

	public function test_ausencia_valida(): void {
		$a = $this->ausencia();

		$this->assertSame( array(), ValidacionAusencia::errores( $a ) );
		$this->assertSame( 'Vacaciones de fin de año', $a['nota'] );
		$this->assertSame( 14, ValidacionAusencia::dias( $a['desde'], $a['hasta'] ) );
	}

	public function test_ausencia_con_errores(): void {
		$this->assertArrayHasKey( 'tipo', ValidacionAusencia::errores( $this->ausencia( array( 'tipo' => 'paseo' ) ) ) );
		$this->assertArrayHasKey( 'hasta', ValidacionAusencia::errores( $this->ausencia( array( 'hasta' => '2026-12-01' ) ) ) );
		$this->assertArrayHasKey( 'desde', ValidacionAusencia::errores( $this->ausencia( array( 'desde' => '2026-02-30' ) ) ) );
		$this->assertArrayHasKey( 'hasta', ValidacionAusencia::errores( $this->ausencia( array( 'hasta' => '2028-12-27' ) ) ) );
		$this->assertArrayHasKey( 'trabajador_id', ValidacionAusencia::errores( $this->ausencia( array( 'trabajador_id' => 0 ) ) ) );
	}

	public function test_ausencias_que_se_cruzan(): void {
		$existentes = array( array( 'desde' => '2026-12-20', 'hasta' => '2026-12-31' ) );

		$this->assertStringContainsString( 'Se cruza', ValidacionAusencia::errores( $this->ausencia(), $existentes )['desde'] ?? '' );
		$this->assertSame( array(), ValidacionAusencia::errores( $this->ausencia( array( 'hasta' => '2026-12-19' ) ), $existentes ) );
	}

	public function test_resumen_ausencia_justificada(): void {
		$r = ResumenDia::calcular( array(), new HorarioDelDia( true, '09:00:00', '19:30:00' ), '2026-12-15', new DateTimeZone( 'America/Lima' ), Fabrica::lima( '2026-12-15 11:00' ), 'Vacaciones' );

		$this->assertSame( ResumenDia::JUSTIFICADO, $r->situacion );
		$this->assertSame( 'Vacaciones', $r->detalle );
	}

	public function test_resumen_feriado(): void {
		$r = ResumenDia::calcular( array(), HorarioDelDia::libre( 'Feriado: Navidad' ), '2026-12-25', new DateTimeZone( 'America/Lima' ), Fabrica::lima( '2026-12-25 11:00' ) );

		$this->assertSame( ResumenDia::LIBRE, $r->situacion );
		$this->assertSame( 'Feriado: Navidad', $r->detalle );
	}

	public function test_si_marca_estando_de_vacaciones_cuenta_la_marca(): void {
		$r = ResumenDia::calcular( array( Fabrica::entrada( '2026-12-15 08:58' ) ), new HorarioDelDia( true, '09:00:00', '19:30:00' ), '2026-12-15', new DateTimeZone( 'America/Lima' ), Fabrica::lima( '2026-12-15 11:00' ), 'Vacaciones' );

		$this->assertSame( ResumenDia::PRESENTE, $r->situacion );
	}
}
