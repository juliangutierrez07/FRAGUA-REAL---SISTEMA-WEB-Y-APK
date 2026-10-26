<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Comanda;
use App\Models\Comprobante;
use App\Models\Configuracion;
use App\Models\EquipoImpresion;
use App\Models\User;
use App\Support\Documentos\RegistroImpresiones;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * M12 — Impresoras (24/10/2026, RF-M12-001 a 014). El sistema vive en el servidor y la impresora en el
 * local: imprime el navegador de un equipo del local (decisión D5). Este servicio lleva:
 *
 *  - Los equipos que imprimen: se registran con un nombre (desde el mismo equipo, o con un código de
 *    6 dígitos donde está abierta la sesión de un Mesero). La clave queda en ese navegador; con ella
 *    manda su señal cada minuto y recibe sus ajustes (qué imprime, si abre solas las comandas).
 *  - La impresión de prueba (ticket de 80 mm con logo, fecha, tildes, regla del ancho y bloques
 *    negros) y su resultado («salió bien», «no salió», «salió cortado»).
 *  - El formato del comprobante y de la comanda (logo, letra, mensaje al pie, hora, quién atiende y
 *    copias), guardado en `configuraciones`, con vista previa.
 *  - El estado: comandas por imprimir y el historial de impresiones con el equipo que las hizo.
 *  - El aviso de equipo desconectado con la caja abierta (M11).
 */
class ImpresionService
{
    public const CABECERA_EQUIPO = 'X-Equipo-Impresion';
    public const RESULTADOS = ['ok' => 'Salió bien', 'fallo' => 'No salió', 'cortado' => 'Salió cortado'];

    /** clave => valor por defecto (en `configuraciones` con el prefijo `impresion_`). */
    public const FORMATO = [
        'ticket_logo' => true,
        'ticket_letra' => 'normal',
        'ticket_mensaje_pie' => '¡Gracias por tu visita!',
        'comanda_letra_grande' => false,
        'comanda_mostrar_hora' => true,
        'comanda_mostrar_mesero' => false,
        'copias_comprobante' => 1,
        'copias_comanda' => 1,
    ];

    // Acciones de la auditoría que forman el historial de impresiones.
    private const HISTORIAL = ['imprimir_comanda', 'imprimir_comprobante', 'imprimir_cierre_diario', 'comanda_no_impresa', 'descartar_comanda', 'prueba_impresion'];

    public function __construct(private readonly AuditoriaService $auditoria) {}

    // ------------------------------------------------------------------ formato

    /** @return array<string, bool|int|string> */
    public function formato(): array
    {
        $formato = [];
        foreach (self::FORMATO as $clave => $defecto) {
            $guardado = Configuracion::obtener("impresion_{$clave}");
            $formato[$clave] = $guardado === null ? $defecto : $this->tipar($defecto, $guardado);
        }

        return $formato;
    }

    public function actualizarFormato(array $valores, User $actor): array
    {
        $antes = $this->formato();
        foreach (self::FORMATO as $clave => $defecto) {
            if (array_key_exists($clave, $valores)) {
                $valor = is_bool($defecto) ? ($valores[$clave] ? '1' : '0') : trim((string) $valores[$clave]);
                Configuracion::fijar("impresion_{$clave}", $valor);
            }
        }
        $despues = $this->formato();
        $cambios = array_filter(array_map(fn ($k) => $antes[$k] !== $despues[$k] ? ['antes' => $antes[$k], 'despues' => $despues[$k]] : null,
            array_combine(array_keys($despues), array_keys($despues))));
        if ($cambios) {
            $this->auditoria->registrar('editar_formato_impresion', 'Configuracion', null, ['cambios' => $cambios], $actor);
        }

        return $despues;
    }

    /** El formato guardado con los cambios que se están probando (vista previa sin guardar). */
    public function formatoCon(array $valores): array
    {
        $formato = $this->formato();
        foreach (self::FORMATO as $clave => $defecto) {
            if (array_key_exists($clave, $valores) && $valores[$clave] !== null) {
                $formato[$clave] = $this->tipar($defecto, is_bool($valores[$clave]) ? ($valores[$clave] ? '1' : '0') : (string) $valores[$clave]);
            }
        }

        return $formato;
    }

    private function tipar(mixed $defecto, string $valor): bool|int|string
    {
        return match (true) {
            is_bool($defecto) => $valor === '1',
            is_int($defecto) => max(1, min(3, (int) $valor)),
            default => $valor,
        };
    }

    // ------------------------------------------------------------------ equipos

    /** Registrar el equipo desde el que se está mirando: devuelve el equipo y su clave (una sola vez). */
    public function registrarAqui(string $nombre, User $actor): array
    {
        $equipo = new EquipoImpresion(['nombre' => trim($nombre)]);
        $clave = $this->nuevaClave();
        $equipo->forceFill(['clave' => $clave, 'registrado_por' => $actor->id, 'ultima_senal_at' => now(), 'ultima_senal_por' => $actor->id])->save();
        $this->auditoria->registrar('registrar_equipo_impresion', 'EquipoImpresion', $equipo->id, ['nombre' => $equipo->nombre, 'como' => 'en este equipo'], $actor);

        return [$equipo->fresh(), $clave];
    }

    /** Crear un equipo para vincularlo después con un código (la tablet de la cocina con sesión de Mesero). */
    public function crearConCodigo(string $nombre, User $actor): array
    {
        $equipo = new EquipoImpresion(['nombre' => trim($nombre)]);
        $equipo->forceFill(['registrado_por' => $actor->id])->save();
        $this->auditoria->registrar('registrar_equipo_impresion', 'EquipoImpresion', $equipo->id, ['nombre' => $equipo->nombre, 'como' => 'con código'], $actor);

        return [$equipo->fresh(), $this->nuevoCodigo($equipo)];
    }

    /** Un código nuevo de 6 dígitos para (re)vincular el equipo; vale unos minutos. */
    public function nuevoCodigo(EquipoImpresion $equipo): string
    {
        do {
            $codigo = (string) random_int(100000, 999999);
        } while (EquipoImpresion::where('codigo_vinculacion', $codigo)->exists());
        $equipo->forceFill(['codigo_vinculacion' => $codigo, 'codigo_vence_at' => now()->addMinutes((int) config('fragua_real.impresion.minutos_codigo', 30))])->save();

        return $codigo;
    }

    /** El equipo escribe el código: queda vinculado (si ya había otro navegador, ese deja de serlo). */
    public function vincular(string $codigo, User $actor): ?array
    {
        $equipo = EquipoImpresion::where('codigo_vinculacion', trim($codigo))->where('codigo_vence_at', '>', now())->first();
        if (! $equipo) {
            return null;
        }
        $clave = $this->nuevaClave();
        $equipo->forceFill(['clave' => $clave, 'codigo_vinculacion' => null, 'codigo_vence_at' => null, 'ultima_senal_at' => now(), 'ultima_senal_por' => $actor->id])->save();
        $this->auditoria->registrar('vincular_equipo_impresion', 'EquipoImpresion', $equipo->id, ['nombre' => $equipo->nombre], $actor);

        return [$equipo->fresh(), $clave];
    }

    public function porClave(?string $clave): ?EquipoImpresion
    {
        return filled($clave) ? EquipoImpresion::where('clave', $clave)->first() : null;
    }

    /** El equipo de la petición en curso (el navegador manda su clave en la cabecera X-Equipo-Impresion). */
    public function equipoActual(): ?EquipoImpresion
    {
        return app()->bound('request') ? $this->porClave(request()->header(self::CABECERA_EQUIPO)) : null;
    }

    /** La señal de cada minuto. Para no escribir de más, se guarda como mucho cada 30 segundos. */
    public function senal(EquipoImpresion $equipo, User $actor): EquipoImpresion
    {
        if ($equipo->ultima_senal_at === null || $equipo->ultima_senal_at->lt(now()->subSeconds(30)) || $equipo->ultima_senal_por !== $actor->id) {
            $equipo->forceFill(['ultima_senal_at' => now(), 'ultima_senal_por' => $actor->id])->save();
        }

        return $equipo;
    }

    public function actualizar(EquipoImpresion $equipo, array $datos, User $actor): EquipoImpresion
    {
        $antes = $equipo->only(array_keys($datos));
        $equipo->fill($datos)->save();
        $cambios = array_filter(array_map(fn ($k) => $antes[$k] !== $equipo->{$k} ? ['antes' => $antes[$k], 'despues' => $equipo->{$k}] : null,
            array_combine(array_keys($datos), array_keys($datos))));
        if ($cambios) {
            $this->auditoria->registrar('editar_equipo_impresion', 'EquipoImpresion', $equipo->id, ['nombre' => $equipo->nombre, 'cambios' => $cambios], $actor);
        }

        return $equipo->fresh();
    }

    public function quitar(EquipoImpresion $equipo, User $actor): void
    {
        $this->auditoria->registrar('quitar_equipo_impresion', 'EquipoImpresion', $equipo->id, ['nombre' => $equipo->nombre], $actor);
        $equipo->delete();
    }

    // ------------------------------------------------------------------ prueba

    public function pdfPrueba(?EquipoImpresion $equipo, User $actor): Response
    {
        $formato = $this->formato();
        $datos = [
            'negocio' => app(NegocioService::class)->publico(),
            'logo' => $formato['ticket_logo'] ? app(NegocioService::class)->logoParaPdf() : null,
            'equipo' => $equipo?->nombre,
            'usuario' => $actor->nombre,
            'fecha' => now(),
            'grande' => $formato['ticket_letra'] === 'grande',
        ];
        $alto = 30 + (18 + ($datos['logo'] ? 5 : 0)) * ($datos['grande'] ? 13.5 : 11.5);

        return Pdf::loadView('impresion.prueba', $datos)
            ->setOption(RegistroImpresiones::OPCIONES_PDF)
            ->setPaper([0, 0, ComandaService::ANCHO_TICKET_PUNTOS, $alto])
            ->stream('prueba-impresion.pdf');
    }

    public function registrarPrueba(EquipoImpresion $equipo, string $resultado, User $actor): EquipoImpresion
    {
        $equipo->forceFill(['ultima_prueba_at' => now(), 'ultima_prueba_resultado' => $resultado, 'ultima_prueba_por' => $actor->id])->save();
        $this->auditoria->registrar('prueba_impresion', 'EquipoImpresion', $equipo->id, [
            'equipo' => $equipo->nombre, 'resultado' => self::RESULTADOS[$resultado],
        ], $actor);

        return $equipo->fresh();
    }

    // ------------------------------------------------------------------ vista previa

    /** Un comprobante o una comanda de muestra con el formato que se está probando (no se guarda nada). */
    public function vistaPrevia(string $tipo, array $formato): Response
    {
        $negocio = app(NegocioService::class)->publico();
        if ($tipo === 'comanda') {
            $comanda = new Comanda(['numero' => 1, 'tipo' => 'inicial']);
            $comanda->setAttribute('contenido', [
                'pedido' => 123, 'tipo_pedido' => 'local', 'canal' => 'EN EL LOCAL', 'mesa' => 'Mesa 4', 'cliente' => null,
                'atiende' => 'Mario (Mesero)', 'generada_at' => now()->toIso8601String(),
                'lineas' => [
                    ['cantidad' => 2, 'nombre' => 'Hamburguesa Clásica', 'extras' => ['Queso extra'], 'observaciones' => 'Una sin cebolla'],
                    ['cantidad' => 1, 'nombre' => 'Combo Fragua', 'componentes' => ['Hamburguesa BBQ', 'Papas', 'Gaseosa']],
                    ['cantidad' => 1, 'nombre' => 'Limonada de coco'],
                ],
            ]);

            return app(ComandaService::class)->ticketPdf($comanda, null, $formato)->stream('vista-previa-comanda.pdf');
        }

        $comprobante = new Comprobante(['numero' => 'FR-000123']);
        $comprobante->setAttribute('detalle', [
            'numero' => 'FR-000123', 'fecha' => now()->toIso8601String(),
            'negocio' => ['nombre' => $negocio['nombre'] ?? 'Fragua Real Burger', 'nit' => $negocio['nit'] ?? null, 'direccion' => $negocio['direccion'] ?? '', 'telefono' => $negocio['telefono'] ?? ''],
            'pedido' => ['id' => 123, 'canal' => 'En el local', 'mesa' => 'Mesa 4', 'cliente' => null],
            'lineas' => [
                ['cantidad' => 2, 'nombre' => 'Hamburguesa Clásica', 'valor' => 44000, 'extras' => ['Queso extra']],
                ['cantidad' => 1, 'nombre' => 'Combo Fragua', 'valor' => 32000],
                ['cantidad' => 1, 'nombre' => 'Limonada de coco', 'valor' => 9000],
            ],
            'subtotal' => 85000, 'descuentos' => [], 'valor_domicilio' => 0, 'total' => 85000,
            'pagos' => [['texto' => 'Efectivo', 'valor' => 85000]],
        ]);

        return app(ComprobanteService::class)->ticketPdf($comprobante, null, $formato)->stream('vista-previa-comprobante.pdf');
    }

    // ------------------------------------------------------------------ estado

    /** Comandas por imprimir y las últimas impresiones (qué, quién, cuándo y en qué equipo). */
    public function estado(): array
    {
        $pendientes = app(ComandaService::class)->porImprimir()->map(fn (Comanda $c) => ComandaService::resumen($c) + [
            'pedido' => [
                'id' => $c->pedido->id, 'tipo' => $c->pedido->tipo, 'estado' => $c->pedido->estado,
                'mesa' => $c->pedido->mesa?->nombre, 'cliente' => $c->pedido->cliente?->nombre ?? $c->pedido->cliente_invitado_nombre,
            ],
        ])->values();

        $historial = Auditoria::with('user:id,nombre')
            ->whereIn('accion', self::HISTORIAL)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(30)->get()
            ->map(fn (Auditoria $a) => [
                'id' => $a->id,
                'accion' => $a->accion,
                'fecha' => $a->created_at,
                'usuario' => $a->user?->nombre,
                'pedido_id' => $a->entidad === 'Pedido' ? $a->entidad_id : null,
                'detalles' => $a->detalles,
            ]);

        return ['pendientes' => $pendientes, 'historial' => $historial];
    }

    // ------------------------------------------------------------------ avisos (M11)

    /**
     * Con la caja de hoy abierta, un equipo que imprime comandas y lleva más de N minutos sin señal se
     * avisa una vez por desconexión (si vuelve y se cae otra vez, se avisa de nuevo).
     */
    public function revisarDesconectados(): int
    {
        if (! app(CajaService::class)->turnoAbierto()) {
            return 0;
        }
        $limite = now()->subMinutes((int) config('fragua_real.impresion.minutos_desconectado', 10));
        $n = 0;
        EquipoImpresion::where('imprime_comandas', true)->whereNotNull('clave')
            ->whereNotNull('ultima_senal_at')->where('ultima_senal_at', '<', $limite)
            ->get()
            ->each(function (EquipoImpresion $e) use (&$n) {
                app(NotificacionService::class)->equipoDesconectado($e);
                $n++;
            });

        return $n;
    }

    private function nuevaClave(): string
    {
        do {
            $clave = Str::random(48);
        } while (DB::table('equipos_impresion')->where('clave', $clave)->exists());

        return $clave;
    }
}
