<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Cómo se exige la presencia en el taller. Se cambia desde los ajustes sin reprogramar.
 */
enum ModoPresencia: string {
	case WIFI_O_QR = 'wifi_o_qr';
	case WIFI_Y_QR = 'wifi_y_qr';
	case SOLO_QR   = 'solo_qr';
	case SOLO_WIFI = 'solo_wifi';

	public function etiqueta(): string {
		return match ( $this ) {
			self::WIFI_O_QR => 'WiFi del taller o QR',
			self::WIFI_Y_QR => 'WiFi del taller y QR',
			self::SOLO_QR   => 'Solo QR',
			self::SOLO_WIFI => 'Solo WiFi del taller',
		};
	}

	/** Devuelve la prueba que cumple el modo, o null si no hay presencia suficiente. */
	public function evaluar( ContextoPresencia $contexto ): ?PruebaPresencia {
		$wifi = $contexto->enRedDelTaller;
		$qr   = $contexto->tieneQr();

		return match ( $this ) {
			self::WIFI_O_QR => match ( true ) {
				$wifi && $qr => PruebaPresencia::WIFI_QR,
				$wifi        => PruebaPresencia::WIFI,
				$qr          => PruebaPresencia::QR,
				default      => null,
			},
			self::WIFI_Y_QR => $wifi && $qr ? PruebaPresencia::WIFI_QR : null,
			self::SOLO_QR   => $qr ? PruebaPresencia::QR : null,
			self::SOLO_WIFI => $wifi ? PruebaPresencia::WIFI : null,
		};
	}

	/** Si escanear el QR puede ayudar a completar la presencia. */
	public function aceptaQr(): bool {
		return self::SOLO_WIFI !== $this;
	}

	public function mensajeSinPresencia( ContextoPresencia $contexto ): string {
		return match ( $this ) {
			self::WIFI_O_QR => 'No estás en el WiFi del taller. Escanea el QR de la pantalla o escribe su código.',
			self::WIFI_Y_QR => $contexto->enRedDelTaller
				? 'Escanea el QR de la pantalla o escribe su código.'
				: 'Conéctate al WiFi del taller y escanea el QR de la pantalla.',
			self::SOLO_QR   => 'Escanea el QR de la pantalla o escribe su código.',
			self::SOLO_WIFI => 'Conéctate al WiFi del taller para marcar.',
		};
	}
}
